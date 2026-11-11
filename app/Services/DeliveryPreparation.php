<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeliveryPreparation
{
    public function __construct(private readonly WarehouseStock $stock, private readonly DeliveryNotePdf $pdf) {}

    public function authorize(User $actor, string $permission): void
    {
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize($permission);
    }

    public function ready(User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'DELIVERY_PREPARE');

        return DB::table('stock_reservations as r')->join('stock_balances as s', 's.id', '=', 'r.balance_id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')->join('customer_orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('delivery_batches as d', 'd.active_order_id', '=', 'o.id')->whereNull('d.id')
            ->whereIn('o.status', ['CONFIRMEE', 'PARTIELLEMENT_EXPEDIEE'])->whereRaw('r.quantity > r.released + r.consumed')
            ->groupBy('o.id', 'o.number', 's.warehouse_id', 'w.code')->orderBy('o.id')
            ->select(['o.id as order_id', 'o.number as order_number', 's.warehouse_id', 'w.code as warehouse_code', DB::raw('SUM(r.quantity - r.released - r.consumed) as remaining_quantity')])
            ->paginate(30);
    }

    public function batches(User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'DELIVERY_PREPARE');
        $page = DB::table('delivery_batches as b')->join('customer_orders as o', 'o.id', '=', 'b.order_id')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.claimed_by', $actor->id)
            ->orderByRaw('b.dispatched_at IS NULL DESC')->orderByDesc('b.created_at')
            ->paginate(30, ['b.*', 'o.number as order_number', 'w.code as warehouse_code']);
        $page->through(fn (object $batch): array => $this->present($batch));

        return $page;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function claim(User $actor, array $values): array
    {
        $this->authorize($actor, 'DELIVERY_PREPARE');
        try {
            return DB::transaction(function () use ($actor, $values): array {
                $order = DB::table('customer_orders')->where('id', $values['order_id'])->lockForUpdate()->firstOrFail();
                abort_unless(in_array($order->status, ['CONFIRMEE', 'PARTIELLEMENT_EXPEDIEE'], true), 409, 'Cette commande ne peut pas être préparée.');
                abort_if(DB::table('delivery_batches')->where('active_order_id', $order->id)->exists(), 409, 'Ce BL est déjà pris par un préparateur.');
                $warehouseId = Str::lower($values['warehouse_id']);
                $reservations = DB::table('stock_reservations as r')->join('stock_balances as s', 's.id', '=', 'r.balance_id')
                    ->where('r.order_id', $order->id)->where('s.warehouse_id', $warehouseId)->whereRaw('r.quantity > r.released + r.consumed')
                    ->lockForUpdate()->get(['r.line_id', 's.product_id', DB::raw('r.quantity - r.released - r.consumed as remaining')]);
                abort_if($reservations->isEmpty(), 409, 'Aucune réservation active dans ce dépôt.');
                $orderLines = collect(json_decode($order->lines, true, flags: JSON_THROW_ON_ERROR))->keyBy(fn (array $line): string => Str::lower($line['id']));
                $batchId = (string) Str::uuid();
                $number = 'BL-'.Str::ulid();
                $now = now('UTC');
                DB::table('delivery_batches')->insert(['id' => $batchId, 'number' => $number, 'order_id' => $order->id, 'warehouse_id' => $warehouseId, 'claimed_by' => $actor->id, 'claimed_at' => $now, 'status' => 'IN_PREPARATION', 'stock_request_key' => (string) Str::uuid(), 'active_order_id' => $order->id, 'created_at' => $now, 'updated_at' => $now]);
                foreach ($reservations->groupBy(fn (object $row): string => Str::lower($row->line_id)) as $lineId => $items) {
                    $original = $orderLines->get($lineId);
                    abort_if($original === null, 409, 'Une réservation ne correspond plus à la commande.');
                    $product = DB::table('products')->where('id', $items->first()->product_id)->firstOrFail();
                    DB::table('delivery_batch_lines')->insert(['id' => (string) Str::uuid(), 'batch_id' => $batchId, 'order_line_id' => $lineId, 'product_id' => $product->id, 'expected_quantity' => $items->sum(fn (object $item): int => (int) $item->remaining), 'units_per_package' => (int) ($original['units_per_package'] ?? 1), 'sku' => $product->sku, 'designation' => $product->designation, 'original_reference' => $product->original_reference, 'created_at' => $now, 'updated_at' => $now]);
                }
                $this->audit($actor, 'delivery.claimed', $batchId, ['order_id' => $order->id, 'number' => $number]);

                return $this->present(DB::table('delivery_batches')->where('id', $batchId)->firstOrFail());
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062 || $exception->getCode() === '23000') {
                abort(409, 'Ce BL est déjà pris par un préparateur.');
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function scan(User $actor, string $batchId, array $values, string $requestKey): array
    {
        $this->authorize($actor, 'DELIVERY_PREPARE');
        abort_unless(Str::isUuid($requestKey), 422, 'Un en-tête Idempotency-Key UUID est requis.');
        $requestKey = Str::lower($requestKey);
        $code = trim($values['code']);
        $hash = hash('sha256', json_encode(['batch_id' => Str::lower($batchId), 'code' => $code, 'mode' => $values['mode'], 'line_id' => $values['line_id'] ?? null], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $batchId, $values, $requestKey, $code, $hash): array {
            $replay = DB::table('delivery_scan_events')->where('request_key', $requestKey)->first();
            if ($replay !== null) {
                abort_if(! hash_equals($replay->request_hash, $hash), 409, 'Cette clé a déjà servi pour un autre scan.');

                return ['replayed' => true, 'batch' => $this->present($this->ownedBatch($actor, $batchId, true))];
            }
            $batch = $this->ownedBatch($actor, $batchId, true);
            abort_unless($batch->status === 'IN_PREPARATION', 409, 'La préparation est déjà validée.');
            [$productIds, $quantity] = $this->resolveCode($code, $values['mode']);
            $eligible = DB::table('delivery_batch_lines')->where('batch_id', $batch->id)->whereIn('product_id', $productIds)
                ->when(isset($values['line_id']), fn ($query) => $query->where('id', Str::lower($values['line_id'])))
                ->lockForUpdate()->get();
            abort_if($eligible->isEmpty(), 422, 'Ce produit ne figure pas dans ce BL');
            abort_if($eligible->count() > 1, 409, 'Référence ambiguë : choisissez explicitement une ligne du BL.');
            $line = $eligible->first();
            abort_if($quantity > (int) $line->expected_quantity - (int) $line->prepared_quantity, 409, 'La quantité scannée dépasse le restant de cette ligne.');
            DB::table('delivery_batch_lines')->where('id', $line->id)->update(['prepared_quantity' => (int) $line->prepared_quantity + $quantity, 'updated_at' => now('UTC')]);
            DB::table('delivery_scan_events')->insert(['id' => (string) Str::uuid(), 'request_key' => $requestKey, 'batch_id' => $batch->id, 'batch_line_id' => $line->id, 'actor_id' => $actor->id, 'mode' => $values['mode'], 'code' => $code, 'quantity' => $quantity, 'request_hash' => $hash, 'created_at' => now('UTC')]);
            $this->audit($actor, 'delivery.scanned', $batch->id, ['line_id' => $line->id, 'mode' => $values['mode'], 'quantity' => $quantity]);

            return ['replayed' => false, 'batch' => $this->present($batch)];
        }, 3);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function validatePreparation(User $actor, string $batchId, array $values): array
    {
        $this->authorize($actor, 'DELIVERY_PREPARE');

        return DB::transaction(function () use ($actor, $batchId, $values): array {
            $batch = $this->ownedBatch($actor, $batchId, true);
            abort_unless($batch->status === 'IN_PREPARATION', 409, 'La préparation est déjà validée.');
            $lines = DB::table('delivery_batch_lines')->where('batch_id', $batch->id)->lockForUpdate()->get();
            abort_if($lines->sum('prepared_quantity') === 0, 409, 'Scannez au moins un article avant de valider.');
            $partial = $lines->contains(fn (object $line): bool => (int) $line->prepared_quantity < (int) $line->expected_quantity);
            abort_if($partial && ! ($values['confirm_partial'] ?? false), 409, 'Des quantités restent à préparer. Confirmez explicitement la livraison partielle.');
            DB::table('delivery_batches')->where('id', $batch->id)->update(['status' => 'PREPARED', 'partial' => $partial, 'prepared_by' => $actor->id, 'prepared_at' => now('UTC'), 'version' => (int) $batch->version + 1, 'updated_at' => now('UTC')]);
            $this->audit($actor, 'delivery.prepared', $batch->id, ['partial' => $partial]);

            return $this->present(DB::table('delivery_batches')->where('id', $batch->id)->firstOrFail());
        }, 3);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function transfer(User $actor, string $batchId, array $values): array
    {
        $this->authorize($actor, 'DELIVERY_TRANSFER');

        return DB::transaction(function () use ($actor, $batchId, $values): array {
            $batch = DB::table('delivery_batches')->where('id', Str::lower($batchId))->lockForUpdate()->firstOrFail();
            abort_unless($batch->status === 'IN_PREPARATION', 409, 'Seul un BL en préparation peut être transféré.');
            $target = User::query()->whereKey($values['target_user_id'])->lockForUpdate()->firstOrFail();
            abort_if(CustomerProfile::where('user_id', $target->id)->exists(), 422, 'Le nouveau préparateur doit être un employé.');
            abort_unless(Gate::forUser($target)->allows('DELIVERY_PREPARE'), 422, 'Le nouvel employé n’est pas habilité à préparer les BL.');
            $before = (int) $batch->claimed_by;
            abort_if($before === (int) $target->id, 422, 'Choisissez un autre préparateur.');
            DB::table('delivery_batches')->where('id', $batch->id)->update(['claimed_by' => $target->id, 'claimed_at' => now('UTC'), 'version' => (int) $batch->version + 1, 'updated_at' => now('UTC')]);
            $this->audit($actor, 'delivery.transferred', $batch->id, ['from_user_id' => $before, 'to_user_id' => $target->id]);

            return $this->present(DB::table('delivery_batches')->where('id', $batch->id)->firstOrFail());
        }, 3);
    }

    /** @return array<string, mixed> */
    public function dispatch(User $actor, string $batchId): array
    {
        $this->authorize($actor, 'DELIVERY_DEPARTURE_CONFIRM');

        return DB::transaction(function () use ($actor, $batchId): array {
            $batch = DB::table('delivery_batches')->where('id', Str::lower($batchId))->lockForUpdate()->firstOrFail();
            if ($batch->status === 'DISPATCHED') {
                return $this->present($batch);
            }
            abort_unless($batch->status === 'PREPARED', 409, 'Validez la préparation avant le départ réel.');
            $lines = DB::table('delivery_batch_lines')->where('batch_id', $batch->id)->where('prepared_quantity', '>', 0)->orderBy('id')->get();
            $payload = $lines->map(fn (object $line): array => ['line_id' => $line->order_line_id, 'quantity' => (int) $line->prepared_quantity])->all();
            $this->stock->dispatchBatch($actor, (int) $batch->order_id, $batch->warehouse_id, $payload, $batch->stock_request_key, $batch->number);
            $order = DB::table('customer_orders')->where('id', $batch->order_id)->lockForUpdate()->firstOrFail();
            $ordered = collect(json_decode($order->lines, true, flags: JSON_THROW_ON_ERROR))->sum(fn (array $line): int => (int) $line['quantity'] * (int) $line['units_per_package']);
            $consumed = (int) DB::table('stock_reservations')->where('order_id', $batch->order_id)->sum('consumed');
            $remaining = max(0, $ordered - $consumed);
            DB::table('customer_orders')->where('id', $batch->order_id)->update(['status' => $remaining === 0 ? 'EXPEDIEE' : 'PARTIELLEMENT_EXPEDIEE', 'version' => DB::raw('version + 1'), 'updated_at' => now('UTC')]);
            DB::table('delivery_batches')->where('id', $batch->id)->update(['status' => 'DISPATCHED', 'dispatched_by' => $actor->id, 'dispatched_at' => now('UTC'), 'active_order_id' => null, 'version' => (int) $batch->version + 1, 'updated_at' => now('UTC')]);
            if (config('receivables.enabled') === true) {
                $unresolved = app(CustomerFinance::class)->synchronize((int) $order->user_id);
                abort_unless($unresolved === [], 409, 'Les conditions historiques et échéances doivent être confirmées avant le départ.');
            }
            $fresh = DB::table('delivery_batches')->where('id', $batch->id)->firstOrFail();
            $document = $this->pdf->render($fresh, $lines);
            DB::table('delivery_batches')->where('id', $batch->id)->update(['document_sha256' => hash('sha256', $document)]);
            $this->audit($actor, 'delivery.dispatched', $batch->id, ['number' => $batch->number, 'remaining_order_units' => $remaining]);

            return $this->present(DB::table('delivery_batches')->where('id', $batch->id)->firstOrFail());
        }, 3);
    }

    public function document(User $actor, string $batchId): string
    {
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
        abort_unless(Gate::forUser($actor)->allows('DELIVERY_PREPARE') || Gate::forUser($actor)->allows('DELIVERY_DEPARTURE_CONFIRM'), 403);
        $batch = DB::table('delivery_batches')->where('id', Str::lower($batchId))->firstOrFail();
        abort_unless((int) $batch->claimed_by === (int) $actor->id || Gate::forUser($actor)->allows('DELIVERY_DEPARTURE_CONFIRM'), 403);
        abort_unless($batch->status === 'DISPATCHED', 409, 'Le BL définitif est disponible après le départ réel.');
        $document = $this->pdf->render($batch, DB::table('delivery_batch_lines')->where('batch_id', $batch->id)->where('prepared_quantity', '>', 0)->orderBy('id')->get());
        abort_unless(hash_equals($batch->document_sha256, hash('sha256', $document)), 500, 'Le document historique ne correspond plus à son empreinte.');

        return $document;
    }

    /** @return array{0: list<int>, 1: int} */
    private function resolveCode(string $code, string $mode): array
    {
        if ($mode !== 'reference') {
            $barcode = DB::table('product_barcodes')->where('barcode', $code)->first();
            abort_if($barcode === null, 422, 'Barcode inconnu.');

            return [[(int) $barcode->product_id], (int) $barcode->units_per_package];
        }
        $searchKey = ProductReferenceNormalizer::searchKey($code);
        $products = DB::table('products')->where('sku', $code)->orWhere('original_reference_search_key', $searchKey)->pluck('id')
            ->merge(DB::table('product_cross_references')->where('reference_search_key', $searchKey)->whereNotNull('verified_at')->pluck('product_id'))
            ->unique()->map(fn ($id): int => (int) $id)->values()->all();
        abort_if($products === [], 422, 'Référence inconnue.');

        return [$products, 1];
    }

    private function ownedBatch(User $actor, string $batchId, bool $lock): object
    {
        $query = DB::table('delivery_batches')->where('id', Str::lower($batchId));
        if ($lock) {
            $query->lockForUpdate();
        }
        $batch = $query->firstOrFail();
        abort_unless((int) $batch->claimed_by === (int) $actor->id, 403, 'Ce BL est attribué à un autre préparateur.');

        return $batch;
    }

    /** @return array<string, mixed> */
    private function present(object $batch): array
    {
        $lines = DB::table('delivery_batch_lines')->where('batch_id', $batch->id)->orderBy('id')->get()->map(fn (object $line): array => ['id' => $line->id, 'order_line_id' => $line->order_line_id, 'product_id' => (int) $line->product_id, 'sku' => $line->sku, 'designation' => $line->designation, 'original_reference' => $line->original_reference, 'units_per_package' => (int) $line->units_per_package, 'expected' => (int) $line->expected_quantity, 'prepared' => (int) $line->prepared_quantity, 'remaining' => (int) $line->expected_quantity - (int) $line->prepared_quantity]);

        return ['id' => $batch->id, 'number' => $batch->number, 'order_id' => (int) $batch->order_id, 'warehouse_id' => $batch->warehouse_id, 'status' => $batch->status, 'partial' => (bool) $batch->partial, 'claimed_by' => (int) $batch->claimed_by, 'claimed_at' => $batch->claimed_at, 'prepared_by' => $batch->prepared_by === null ? null : (int) $batch->prepared_by, 'prepared_at' => $batch->prepared_at, 'dispatched_by' => $batch->dispatched_by === null ? null : (int) $batch->dispatched_by, 'dispatched_at' => $batch->dispatched_at, 'version' => (int) $batch->version, 'document_url' => $batch->status === 'DISPATCHED' ? route('delivery.document', $batch->id) : null, 'lines' => $lines->all()];
    }

    /** @param array<string, mixed> $changes */
    private function audit(User $actor, string $action, string $batchId, array $changes): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'request_id' => app(AuditContext::class)->requestId ?? (string) Str::uuid(), 'action' => $action, 'object_type' => 'delivery_batch', 'object_id' => $batchId, 'result' => 'SUCCESS', 'changes' => $changes, 'occurred_at' => now('UTC')]);
    }
}
