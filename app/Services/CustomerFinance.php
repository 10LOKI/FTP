<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CustomerFinance
{
    public const MODES = ['cash', 'cheque', 'postdated_cheque'];

    public function authorize(User $actor, string $permission = 'PAYMENT_READ'): void
    {
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize($permission);
    }

    public function enabled(): void
    {
        abort_unless(config('receivables.enabled') === true, 503, 'Les règlements attendent l’activation de la politique et du contrat commercial approuvés.');
    }

    /** All financial mutations serialize on this row, including trusted source imports. */
    public function lock(int $customerId): object
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Une transaction financière est requise.');
        CustomerProfile::where('user_id', $customerId)->firstOrFail();
        DB::table('customer_finance_accounts')->insertOrIgnore(['customer_id' => $customerId, 'blocked' => false, 'version' => 1]);

        return DB::table('customer_finance_accounts')->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();
    }

    /** Imports only dispatched base-unit quantities at confirmed historical base-unit prices.
     * No due-days arithmetic: the confirmed producer must provide an actual calendar date.
     *
     * @return list<string> Unresolved delivery line IDs.
     */
    public function synchronize(int $customerId): array
    {
        $this->enabled();
        $this->lock($customerId);
        $missing = [];
        $batches = DB::table('delivery_batches')->join('customer_orders', 'customer_orders.id', '=', 'delivery_batches.order_id')
            ->where('customer_orders.user_id', $customerId)->where('delivery_batches.status', 'DISPATCHED')
            ->orderBy('delivery_batches.id')->lockForUpdate()->get(['delivery_batches.id', 'delivery_batches.number', 'customer_orders.lines', 'customer_orders.commercial_snapshot']);
        foreach ($batches as $batch) {
            $commercial = json_decode($batch->commercial_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR)['billing_v1'] ?? [];
            $terms = $commercial['payment_terms'] ?? [];
            $valid = ($commercial['status'] ?? null) === 'confirmed' && ($commercial['customer_id'] ?? null) === $customerId
                && is_int($commercial['confirmed_by'] ?? null) && is_string($commercial['confirmed_at'] ?? null) && trim($commercial['confirmed_at']) !== ''
                && ($commercial['currency'] ?? null) === 'MAD' && ($commercial['tax_basis'] ?? null) === 'TTC'
                && ($commercial['lines_sha256'] ?? null) === InvoiceIssuanceContext::linesHash($batch->lines)
                && in_array($terms['mode'] ?? null, self::MODES, true) && $this->validDate($terms['due_date'] ?? null)
                && is_string($terms['label'] ?? null) && trim($terms['label']) !== '';
            $originals = collect(json_decode($batch->lines, true, flags: JSON_THROW_ON_ERROR))->keyBy('id');
            foreach (DB::table('delivery_batch_lines')->where('batch_id', $batch->id)->where('prepared_quantity', '>', 0)->orderBy('id')->lockForUpdate()->get() as $line) {
                if (DB::table('customer_receivables')->where('delivery_line_id', $line->id)->lockForUpdate()->first() !== null) {
                    continue;
                }
                $original = $originals->get($line->order_line_id);
                $price = null;
                if (is_string($original['unit_price'] ?? null)) {
                    try {
                        $price = PriceAmount::decimal(PriceAmount::minorUnits($original['unit_price']));
                    } catch (\InvalidArgumentException) {
                        $price = null;
                    }
                }
                if (! $valid || ! is_array($original) || $price === null
                    || (int) ($original['product_id'] ?? 0) !== (int) $line->product_id
                    || ($original['pricing']['currency'] ?? null) !== 'MAD' || ($original['pricing']['tax_basis'] ?? null) !== 'TTC' || (int) $line->prepared_quantity < 1) {
                    $missing[] = $line->id;

                    continue;
                }
                $amount = bcmul($price, (string) $line->prepared_quantity, 2);
                abort_if(bccomp($amount, '9999999999999999999999.99', 2) > 0, 409, 'Montant historique hors capacité.');
                DB::table('customer_receivables')->insert(['id' => (string) Str::uuid(), 'customer_id' => $customerId,
                    'delivery_line_id' => $line->id, 'source_number' => $batch->number, 'amount' => $amount,
                    'due_date' => $terms['due_date'], 'due_origin' => 'confirmed_order', 'mode' => $terms['mode'],
                    'terms' => json_encode(collect($terms)->only(['mode', 'label', 'due_date', 'due_days'])->all(), JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            }
        }

        return $missing;
    }

    private function validDate(mixed $date): bool
    {
        return is_string($date) && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** @return array<string, mixed> */
    public function state(int $customerId): array
    {
        $account = $this->lock($customerId);
        $missing = config('receivables.enabled') === true ? $this->synchronize($customerId)
            : DB::table('delivery_batch_lines')->join('delivery_batches', 'delivery_batches.id', '=', 'delivery_batch_lines.batch_id')
                ->join('customer_orders', 'customer_orders.id', '=', 'delivery_batches.order_id')
                ->leftJoin('customer_receivables', 'customer_receivables.delivery_line_id', '=', 'delivery_batch_lines.id')
                ->where('customer_orders.user_id', $customerId)->where('delivery_batches.status', 'DISPATCHED')
                ->where('delivery_batch_lines.prepared_quantity', '>', 0)->whereNull('customer_receivables.id')
                ->pluck('delivery_batch_lines.id')->all();
        $operations = DB::table('customer_finance_operations')->where('customer_id', $customerId)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get()
            ->map(fn (object $row): array => ['id' => $row->id, 'action' => $row->action, 'created_at' => $row->created_at,
                ...json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)])->all();
        $collected = array_column(array_filter($operations, fn (array $op): bool => $op['action'] === 'collect'), 'payment_id');
        $reversed = array_column(array_filter($operations, fn (array $op): bool => $op['action'] === 'reverse'), 'payment_id');
        $balances = [];
        $pending = '0.00';
        foreach ($operations as $op) {
            if ($op['action'] === 'payment') {
                if (in_array($op['id'], $reversed, true)) {
                    continue;
                }
                if ($op['mode'] !== 'cash' && ! in_array($op['id'], $collected, true)) {
                    $pending = bcadd($pending, $op['amount'], 2);

                    continue;
                }
            } elseif ($op['action'] !== 'credit_apply') {
                continue;
            }
            foreach ($op['allocations'] as $allocation) {
                $key = $allocation['receivable_id'];
                $balances[$key] = bcadd($balances[$key] ?? '0.00', $allocation['amount'], 2);
            }
        }
        $invoices = DB::table('billing_quantity_allocations')->join('customer_invoices', 'customer_invoices.id', '=', 'billing_quantity_allocations.source_id')
            ->where('billing_quantity_allocations.kind', 'invoice')->where('customer_invoices.customer_id', $customerId)
            ->orderBy('customer_invoices.issued_at')->orderBy('customer_invoices.id')->lockForUpdate()
            ->get(['billing_quantity_allocations.delivery_line_id', 'billing_quantity_allocations.quantity',
                'customer_invoices.id', 'customer_invoices.number', 'customer_invoices.issued_at'])
            ->groupBy('delivery_line_id');
        $debt = '0.00';
        $overdue = '0.00';
        $receivables = [];
        foreach (DB::table('customer_receivables')->where('customer_id', $customerId)->orderBy('due_date')->orderBy('id')->lockForUpdate()->get() as $row) {
            $balance = bcsub($row->amount, $balances[$row->id] ?? '0.00', 2);
            $debt = bcadd($debt, $balance, 2);
            $late = $row->due_date < today()->toDateString() && bccomp($balance, '0', 2) > 0;
            if ($late) {
                $overdue = bcadd($overdue, $balance, 2);
            }
            $receivables[] = ['id' => $row->id, 'delivery_line_id' => $row->delivery_line_id, 'source_number' => $row->source_number,
                'amount' => bcadd((string) $row->amount, '0', 2), 'balance' => $balance, 'due_date' => $row->due_date, 'due_origin' => $row->due_origin,
                'mode' => $row->mode, 'invoices' => $invoices->get($row->delivery_line_id, collect())->map(fn (object $invoice): array => [
                    'id' => $invoice->id, 'number' => $invoice->number, 'issued_at' => $invoice->issued_at, 'quantity' => (int) $invoice->quantity,
                ])->values()->all(), 'status' => bccomp($balance, '0', 2) === 0 ? 'paid' : ($late ? 'overdue' : (bccomp($balance, $row->amount, 2) < 0 ? 'partially_paid' : 'unpaid'))];
        }
        $credit = '0.00';
        $refunds = '0.00';
        $rights = [];
        foreach (DB::table('customer_credit_rights')->where('customer_id', $customerId)->orderBy('id')->lockForUpdate()->get() as $right) {
            $remaining = $right->amount;
            foreach ($operations as $op) {
                if (in_array($op['action'], ['credit_apply', 'refund'], true) && $op['credit_id'] === $right->id) {
                    $remaining = bcsub($remaining, $op['amount'], 2);
                    if ($op['action'] === 'refund') {
                        $refunds = bcadd($refunds, $op['amount'], 2);
                    }
                }
            }
            $rights[] = ['id' => $right->id, 'amount' => bcadd((string) $right->amount, '0', 2), 'available' => $remaining];
            $credit = bcadd($credit, $remaining, 2);
        }
        if (config('receivables.enabled') === true && bccomp($overdue, '0', 2) > 0 && ! $account->blocked) {
            DB::table('customer_finance_accounts')->where('customer_id', $customerId)->update(['blocked' => true, 'version' => (int) $account->version + 1]);
            $account->blocked = true;
            $account->version++;
            $this->audit(null, 'finance.overdue_blocked', (string) $customerId);
        }
        $terms = DB::table('customer_payment_terms')->where('customer_id', $customerId)->orderByDesc('version')->lockForUpdate()->first();

        return ['customer_id' => $customerId, 'currency' => 'MAD', 'tax_basis' => 'TTC', 'enabled' => config('receivables.enabled') === true,
            'complete' => $missing === [], 'unresolved_delivery_count' => count($missing), 'debt' => $debt, 'credit' => $credit,
            'refunds' => $refunds, 'pending_cheques' => $pending, 'overdue' => $overdue, 'blocked' => (bool) $account->blocked,
            'version' => (int) $account->version, 'terms' => $terms === null ? null : ['mode' => $terms->mode, 'label' => $terms->label, 'version' => $terms->version],
            'receivables' => $receivables, 'credits' => $rights, 'operations' => $operations];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function operate(User $actor, int $customerId, string $action, array $input): array
    {
        $permission = match ($action) {
            'terms' => 'CUSTOMER_TERMS_MANAGE', 'reverse' => 'PAYMENT_CORRECT',
            'unblock' => 'PAYMENT_UNBLOCK', default => 'PAYMENT_RECORD',
        };
        $this->authorize($actor, $permission);
        $this->enabled();
        if ($action === 'unblock') {
            abort_unless(app(AdministratorAccess::class)->isUsable($actor)
                && $actor->roles()->where('is_protected', true)->exists(), 403, 'Le déblocage exige un administrateur protégé.');
        }

        return DB::transaction(function () use ($actor, $customerId, $action, $input, $permission): array {
            $account = $this->lock($customerId);
            $this->authorize($actor, $permission);
            $key = Str::lower($input['request_key']);
            foreach (['payment_id', 'credit_id'] as $field) {
                if (isset($input[$field])) {
                    $input[$field] = Str::lower($input[$field]);
                }
            }
            if (isset($input['allocations'])) {
                $input['allocations'] = collect($input['allocations'])->map(fn (array $row): array => ['receivable_id' => Str::lower($row['receivable_id']), 'amount' => $row['amount']])->sortBy('receivable_id')->values()->all();
            }
            if (isset($input['version'])) {
                $input['version'] = (int) $input['version'];
            }
            ksort($input);
            unset($input['request_key']);
            $hash = hash('sha256', json_encode([$customerId, $action, $input], JSON_THROW_ON_ERROR));
            $existing = DB::table('customer_finance_operations')->where('request_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless((int) $existing->actor_id === (int) $actor->id && hash_equals($existing->request_hash, $hash), 409, 'Cette clé désigne une autre opération.');

                return ['id' => $existing->id, 'replayed' => true];
            }
            $state = $this->state($customerId);
            $id = (string) Str::uuid();
            if ($action === 'terms') {
                abort_unless($input['version'] === $state['version'], 409, 'Le compte a changé. Rechargez.');
                DB::table('customer_payment_terms')->insert(['id' => (string) Str::uuid(), 'customer_id' => $customerId,
                    'version' => $state['version'] + 1, 'mode' => $input['mode'], 'label' => $input['label'],
                    'reason' => $input['reason'], 'recorded_by' => $actor->id, 'created_at' => now('UTC')]);
            } elseif ($action === 'payment') {
                abort_unless($this->validDate($input['payment_date'] ?? null)
                    && $input['payment_date'] <= today()->toDateString(), 422, 'La date réelle du règlement est requise et ne peut être future.');
                abort_unless(CustomerProfile::where('user_id', $customerId)->where('approval', 'APPROUVE')->exists(), 409, 'Le compte doit être approuvé.');
                abort_unless($state['complete'], 409, 'Des départs attendent leurs conditions historiques.');
                abort_unless($state['terms'] !== null && $state['terms']['mode'] === $input['mode'], 409, 'Le mode actif du client ne correspond pas.');
                if ($input['mode'] !== 'cash') {
                    abort_unless(is_string($input['reference'] ?? null) && trim($input['reference']) !== '' && $this->validDate($input['maturity_date'] ?? null), 422, 'Référence et échéance du chèque requises.');
                }
                $this->allocations($state, $input['allocations'], $input['amount']);
            } elseif (in_array($action, ['collect', 'reverse'], true)) {
                $payment = collect($state['operations'])->firstWhere('id', $input['payment_id']);
                abort_unless($payment !== null && $payment['action'] === 'payment', 404);
                abort_if(collect($state['operations'])->contains(fn (array $op): bool => $op['action'] === 'reverse' && $op['payment_id'] === $payment['id']), 409, 'Ce règlement est déjà corrigé.');
                if ($action === 'collect') {
                    abort_if($payment['mode'] === 'cash' || collect($state['operations'])->contains(fn (array $op): bool => $op['action'] === 'collect' && $op['payment_id'] === $payment['id']), 409, 'Ce règlement est déjà encaissé.');
                    abort_if($payment['maturity_date'] > today()->toDateString(), 409, 'Le chèque n’est pas encore à échéance.');
                    $this->allocations($state, $payment['allocations'], $payment['amount'], $payment['id']);
                }
            } elseif (in_array($action, ['credit_apply', 'refund'], true)) {
                $right = collect($state['credits'])->firstWhere('id', $input['credit_id']);
                abort_unless($right !== null, 404);
                abort_if(bccomp($input['amount'], $right['available'], 2) > 0, 409, 'Le crédit a déjà été utilisé ou remboursé.');
                if ($action === 'credit_apply') {
                    $this->allocations($state, $input['allocations'], $input['amount']);
                }
            } elseif ($action === 'unblock') {
                abort_unless($input['version'] === $state['version'] && $account->blocked, 409, 'Le blocage a changé.');
                abort_unless($state['complete'] && bccomp($state['overdue'], '0', 2) === 0, 409, 'Régularisez les échéances avant le déblocage.');
                DB::table('customer_finance_accounts')->where('customer_id', $customerId)->update(['blocked' => false]);
            } else {
                abort(404);
            }
            DB::table('customer_finance_operations')->insert(['id' => $id, 'request_key' => $key, 'request_hash' => $hash,
                'customer_id' => $customerId, 'actor_id' => $actor->id, 'action' => $action, 'payload' => json_encode($input, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            DB::table('customer_finance_accounts')->where('customer_id', $customerId)->increment('version');
            $this->audit($actor->id, 'finance.'.$action, $id);

            return ['id' => $id, 'replayed' => false];
        }, 3);
    }

    /** @param array<string, mixed> $state
     * @param  list<array{receivable_id: string, amount: string}>  $allocations
     */
    private function allocations(array $state, array $allocations, string $amount, ?string $exceptPayment = null): void
    {
        $total = '0.00';
        foreach ($allocations as $allocation) {
            $row = collect($state['receivables'])->firstWhere('id', $allocation['receivable_id']);
            abort_unless($row !== null, 404);
            $available = $row['balance'];
            // An uncollected cheque holds its intended allocation but never reduces debt.
            foreach ($state['operations'] as $op) {
                if ($op['action'] !== 'payment' || $op['mode'] === 'cash' || $op['id'] === $exceptPayment) {
                    continue;
                }
                $closed = collect($state['operations'])->contains(fn (array $other): bool => in_array($other['action'], ['collect', 'reverse'], true) && $other['payment_id'] === $op['id']);
                if (! $closed) {
                    foreach ($op['allocations'] as $hold) {
                        if ($hold['receivable_id'] === $row['id']) {
                            $available = bcsub($available, $hold['amount'], 2);
                        }
                    }
                }
            }
            abort_if(bccomp($allocation['amount'], $available, 2) > 0, 409, 'L’affectation dépasse le solde disponible.');
            $total = bcadd($total, $allocation['amount'], 2);
        }
        abort_unless(bccomp($total, $amount, 2) === 0, 422, 'Le total des affectations doit égaler le montant.');
    }

    /** Trusted FTP-29 integration port; never exposed as a browser credit-creation API.
     * @return string The single right shared by credit allocations and real refunds.
     */
    public function registerCredit(int $customerId, string $sourceKey, string $amount): string
    {
        $this->enabled();
        abort_unless(DB::transactionLevel() > 0, 500);
        $this->lock($customerId);
        abort_unless(preg_match('/\A[0-9]{1,22}\.[0-9]{2}\z/D', $amount) === 1 && bccomp($amount, '0', 2) > 0 && strlen($sourceKey) <= 120 && trim($sourceKey) !== '', 422);
        $existing = DB::table('customer_credit_rights')->where('source_key', $sourceKey)->lockForUpdate()->first();
        if ($existing !== null) {
            abort_unless((int) $existing->customer_id === $customerId && bccomp($existing->amount, $amount, 2) === 0, 409);

            return $existing->id;
        }
        $id = (string) Str::uuid();
        DB::table('customer_credit_rights')->insert(['id' => $id, 'customer_id' => $customerId, 'source_key' => $sourceKey, 'amount' => $amount, 'created_at' => now('UTC')]);
        $this->audit(null, 'finance.credit_registered', $id);

        return $id;
    }

    /** @return array{allowed: bool, reason: string} */
    public function creditCheck(int $customerId): array
    {
        if (config('receivables.enabled') !== true) {
            return ['allowed' => false, 'reason' => 'policy_disabled'];
        }
        $state = $this->state($customerId);
        if (! $state['complete'] || $state['terms'] === null) {
            return ['allowed' => false, 'reason' => 'historical_terms_required'];
        }
        if ($state['blocked'] || bccomp($state['overdue'], '0', 2) > 0) {
            return ['allowed' => false, 'reason' => 'overdue_block'];
        }

        return ['allowed' => true, 'reason' => 'clear'];
    }

    /** Internal dashboard reminders; no external messages are sent.
     * @return array<string, mixed>
     */
    public function review(int $customerId): array
    {
        return DB::transaction(function () use ($customerId): array {
            $state = $this->state($customerId);
            if (config('receivables.enabled') !== true) {
                return $state;
            }
            $days = config('receivables.reminder_days');
            if (is_int($days) && $days > 0) {
                foreach ($state['receivables'] as $row) {
                    if (bccomp($row['balance'], '0', 2) > 0 && $row['due_date'] >= today()->toDateString() && $row['due_date'] <= today()->addDays($days)->toDateString()) {
                        DB::table('customer_finance_reminders')->insertOrIgnore(['id' => (string) Str::uuid(), 'receivable_id' => $row['id'], 'created_at' => now('UTC')]);
                    }
                }
            }

            return $this->state($customerId);
        }, 3);
    }

    private function audit(?int $actorId, string $action, string $id): void
    {
        app(AuditWriter::class)->record(['actor_id' => $actorId, 'request_id' => (string) Str::uuid(),
            'action' => $action, 'object_type' => 'customer_finance', 'object_id' => $id,
            'result' => 'SUCCESS', 'occurred_at' => now('UTC')]);
    }
}
