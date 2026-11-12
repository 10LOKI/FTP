<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SalesQuoteDrafts
{
    public function __construct(private readonly SalesLineProposal $pricing) {}

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function prepare(User $actor, int $orderId, array $values, bool $save = false): array
    {
        Gate::forUser($actor)->authorize('ORDER_REVIEW_MANAGE');
        abort_if(CustomerProfile::query()->where('user_id', $actor->id)->exists(), 403);

        return DB::transaction(function () use ($actor, $orderId, $values, $save): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order !== null, 404);
            $review = DB::table('sales_order_reviews')->where('order_id', $orderId)->first();
            abort_unless($review !== null && (int) $review->claimed_by === $actor->id, 403);
            $identity = [(int) $values['expected_version'], $values['valid_until'], $values['preview_token'] ?? null];
            if (isset($values['payment_due_date']) || isset($values['fees'])) {
                $identity[] = ['payment_due_date' => $values['payment_due_date'] ?? null, 'fees' => $values['fees'] ?? null];
            }
            $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            if ($save) {
                $existing = DB::table('sales_quote_drafts')->where('order_id', $orderId)->where('request_key', Str::lower($values['request_key']))->first();
                if ($existing !== null) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Clé déjà utilisée pour un autre brouillon.');

                    return ['draft_id' => $existing->id, 'revision' => (int) $existing->revision, 'version' => (int) $existing->order_version, 'replayed' => true];
                }
            }
            abort_unless(in_array($order->status, ['EN_VERIFICATION', 'ATTENTE_ACCORD'], true) && (int) $order->version === (int) $values['expected_version'], 409, 'État ou version périmés.');
            abort_unless($values['valid_until'] > now('UTC')->toDateString(), 422, 'La validité doit être future (UTC).');
            $snapshot = $this->snapshot($order, $values['valid_until']);
            $snapshot = array_replace($snapshot, app(QuotePaymentTerms::class)->capture((int) $order->user_id, $values['payment_due_date'] ?? null),
                ['fees' => $values['fees'] ?? null]);
            $stable = $snapshot;
            foreach ($stable['lines'] as &$line) {
                unset($line['pricing']['calculated_at']);
            }
            unset($line);
            $token = hash('sha256', json_encode($stable, JSON_THROW_ON_ERROR));
            if (! $save) {
                return ['snapshot' => $snapshot, 'preview_token' => $token, 'version' => (int) $order->version];
            }
            abort_unless(hash_equals($token, $values['preview_token']), 409, 'Le tarif ou la proposition ont changé. Prévisualisez à nouveau.');
            $id = (string) Str::uuid();
            $revision = (int) DB::table('sales_quote_drafts')->where('order_id', $orderId)->max('revision') + 1;
            $version = (int) $order->version + 1;
            DB::table('sales_quote_drafts')->insert(['id' => $id, 'order_id' => $orderId, 'actor_id' => $actor->id,
                'revision' => $revision, 'order_version' => $version, 'request_key' => Str::lower($values['request_key']),
                'request_hash' => $hash, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            DB::table('customer_orders')->where('id', $orderId)->update(['version' => $version, 'updated_at' => now()]);
            AuditEvent::create(['actor_id' => $actor->id, 'request_id' => (string) Str::uuid(), 'action' => 'sales.quote_draft_created',
                'object_type' => 'customer_order', 'object_id' => (string) $orderId, 'result' => 'SUCCESS',
                'changes' => ['after' => ['draft_id' => $id, 'revision' => $revision, 'version' => $version]], 'occurred_at' => now('UTC')]);

            return ['draft_id' => $id, 'revision' => $revision, 'version' => $version, 'replayed' => false];
        }, 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(object $order, string $validUntil): array
    {
        $snapshot = ['schema_version' => 1, 'status' => 'DRAFT', 'order_number' => $order->number,
            'source_order_version' => (int) $order->version, 'valid_until' => $validUntil,
            'customer' => Arr::only(json_decode($order->customer_snapshot, true, flags: JSON_THROW_ON_ERROR), ['id', 'customer_code', 'type', 'company_name']),
            'payment_terms' => null, 'payment_terms_status' => 'contract_pending', 'fees' => null, 'requires_agreement' => true];
        $revisions = DB::table('sales_order_revisions')->where('order_id', $order->id)->orderBy('order_version')->get()->keyBy('kind');
        foreach (['contact', 'delivery_address', 'billing_address'] as $field) {
            $fields = $field === 'contact' ? ['name', 'email', 'phone'] : ['address', 'city', 'postal_code', 'country'];
            $snapshot[$field] = Arr::only(json_decode($revisions->get($field)?->after ?? $order->{$field}, true, flags: JSON_THROW_ON_ERROR), $fields);
        }
        $lines = $revisions->has('lines') ? json_decode($revisions->get('lines')->after, true, flags: JSON_THROW_ON_ERROR)['lines']
            : json_decode($order->lines, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(count($lines) >= 1 && count($lines) <= 200, 422, 'Les lignes du brouillon sont requises.');
        $requested = array_map(fn (array $line): array => Arr::only($line, ['product_id', 'quantity', 'units_per_package']), $lines);
        $priced = $this->pricing->calculate((int) $order->user_id, $requested);
        $priced['lines'] = array_map(fn (array $line, int $index): array => ['id' => $lines[$index]['id']] + $line, $priced['lines'], array_keys($priced['lines']));

        return $snapshot + Arr::only($priced, ['lines', 'estimated_subtotal', 'currency', 'tax_basis']);
    }
}
