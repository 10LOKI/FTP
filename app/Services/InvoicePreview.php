<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InvoicePreview
{
    public function authorize(User $actor): void
    {
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize('INVOICE_PREVIEW');
    }

    public function deliveries(User $actor, int $customerId): LengthAwarePaginator
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($customerId): LengthAwarePaginator {
            $page = DB::table('delivery_batches as b')
                ->join('customer_orders as o', 'o.id', '=', 'b.order_id')
                ->where('o.user_id', $customerId)->where('b.status', 'DISPATCHED')
                ->whereNotNull('b.dispatched_at')->whereNotNull('b.dispatched_by')->whereNotNull('b.document_sha256')
                ->orderByDesc('b.dispatched_at')->orderBy('b.id')->paginate(20, ['b.*']);

            return $page->through(fn (object $batch): array => $this->delivery($batch));
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(User $actor, array $input): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($input): array {
            $selected = collect($input['lines'])->keyBy(fn (array $line): string => Str::lower($line['delivery_line_id']));
            $rows = DB::table('delivery_batch_lines')->whereIn('id', $selected->keys())->orderBy('batch_id')->orderBy('id')->get();
            abort_unless($rows->count() === $selected->count(), 422, 'Une ligne de BL est inconnue.');
            $batches = DB::table('delivery_batches')->whereIn('id', $rows->pluck('batch_id'))->orderBy('id')->lockForUpdate()->get();
            $lines = [];
            $total = '0.00';
            foreach ($batches as $batch) {
                $order = DB::table('customer_orders')->where('id', $batch->order_id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $order->user_id === (int) $input['customer_id'], 422, 'Sélectionnez uniquement les BL du même client.');
                abort_unless($batch->status === 'DISPATCHED' && $batch->dispatched_at !== null && $batch->dispatched_by !== null && $batch->document_sha256 !== null, 409, 'Ce BL ne possède pas de départ validé.');
                $delivery = $this->delivery($batch, true);
                foreach ($delivery['lines'] as $line) {
                    $selection = $selected->get($line['id']);
                    if ($selection === null) {
                        continue;
                    }
                    abort_unless(hash_equals($line['availability_token'], $selection['availability_token']), 409, 'Les données du BL ont changé. Rechargez la sélection.');
                    abort_unless($line['pricing_status'] === 'historical_mad_ttc', 409, 'Le prix historique MAD TTC de cette ligne doit être confirmé.');
                    abort_if((int) $selection['quantity'] > $line['remaining_quantity'], 409, 'La quantité dépasse le net encore facturable.');
                    $line['quantity'] = (int) $selection['quantity'];
                    $line['total'] = bcmul($line['unit_price'], (string) $line['quantity'], 2);
                    $total = bcadd($total, $line['total'], 2);
                    $lines[] = $line;
                }
            }

            $result = ['customer_id' => (int) $input['customer_id'], 'currency' => 'MAD', 'tax_basis' => 'TTC',
                'status' => 'preview', 'creates_invoice' => false, 'lines' => $lines, 'total' => $total];
            $result['issuance'] = app(InvoiceIssuer::class)->readiness($result);

            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function delivery(object $batch, bool $lock = false): array
    {
        $order = DB::table('customer_orders')->where('id', $batch->order_id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $originals = collect(json_decode($order->lines, true, flags: JSON_THROW_ON_ERROR))->keyBy(fn (array $line): string => Str::lower((string) $line['id']));
        $lines = DB::table('delivery_batch_lines')->where('batch_id', $batch->id)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(function (object $line) use ($batch, $order, $originals, $lock): array {
            $snapshot = $originals->get(Str::lower($line->order_line_id));
            $allocations = DB::table('billing_quantity_allocations')->where('delivery_line_id', $line->id)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            $invoiced = (int) $allocations->where('kind', 'invoice')->sum('quantity');
            $returned = (int) $allocations->where('kind', 'return_before_invoice')->sum('quantity');
            $delivered = (int) $line->prepared_quantity;
            $remaining = $delivered - $invoiced - $returned;
            abort_if($remaining < 0 || $invoiced < 0 || $returned < 0, 409, 'Les allocations de cette ligne doivent être rapprochées avant facturation.');
            $price = $snapshot['unit_price'] ?? null;
            $priced = is_array($snapshot) && (int) ($snapshot['product_id'] ?? 0) === (int) $line->product_id
                && ($snapshot['pricing']['currency'] ?? null) === 'MAD' && ($snapshot['pricing']['tax_basis'] ?? null) === 'TTC'
                && is_string($price);
            if ($priced) {
                try {
                    $price = PriceAmount::decimal(PriceAmount::minorUnits($price));
                } catch (InvalidArgumentException) {
                    $priced = false;
                }
            }
            $safe = ['id' => $line->id, 'delivery_id' => $batch->id, 'delivery_number' => $batch->number,
                'order_id' => (int) $order->id, 'order_number' => $order->number, 'order_line_id' => $line->order_line_id,
                'sku' => $line->sku, 'designation' => $line->designation, 'original_reference' => $line->original_reference,
                'units_per_package' => (int) $line->units_per_package, 'quantity_unit' => 'piece',
                'delivered_quantity' => $delivered, 'invoiced_quantity' => $invoiced, 'returned_before_invoice_quantity' => $returned,
                'remaining_quantity' => $remaining, 'unit_price' => $priced ? $price : null,
                'pricing_status' => $priced ? 'historical_mad_ttc' : 'historical_price_unavailable',
                'source_url' => route('invoice-preview.delivery', $batch->id)];
            $safe['availability_token'] = hash('sha256', json_encode([$safe, $batch->version, $order->version], JSON_THROW_ON_ERROR));

            return $safe;
        })->all();

        return ['id' => $batch->id, 'number' => $batch->number, 'order_number' => $order->number,
            'dispatched_at' => $batch->dispatched_at, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    public function source(User $actor, string $batchId): array
    {
        $this->authorize($actor);
        $batch = DB::table('delivery_batches')->where('id', Str::lower($batchId))->firstOrFail();
        abort_unless($batch->status === 'DISPATCHED' && $batch->dispatched_at !== null && $batch->dispatched_by !== null && $batch->document_sha256 !== null, 409);

        return $this->delivery($batch);
    }
}
