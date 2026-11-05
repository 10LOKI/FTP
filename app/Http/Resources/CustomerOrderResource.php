<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class CustomerOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => (string) $order->id, 'number' => $order->number, 'version' => (int) $order->version,
            'status' => $order->status, 'submitted_at' => $order->created_at,
            'contact' => Arr::only(json_decode($order->contact, true, 512, JSON_THROW_ON_ERROR), ['name', 'email', 'phone']),
            'delivery_address' => Arr::only(json_decode($order->delivery_address, true, 512, JSON_THROW_ON_ERROR), ['address', 'city', 'postal_code', 'country']),
            'billing_address' => Arr::only(json_decode($order->billing_address, true, 512, JSON_THROW_ON_ERROR), ['address', 'city', 'postal_code', 'country']),
            'lines' => array_map($this->line(...), json_decode($order->lines, true, 512, JSON_THROW_ON_ERROR)),
            'pricing_status' => $order->pricing_status,
            'commercial_terms' => $order->commercial_snapshot === null
                ? ['fees' => null, 'discounts' => null, 'currency' => null, 'status' => 'pending_human_review']
                : $this->commercialTerms(json_decode($order->commercial_snapshot, true, 512, JSON_THROW_ON_ERROR)),
            'totals' => ['catalogue_subtotal' => bcadd((string) $order->catalogue_subtotal, '0', 2)],
            'customer_note' => $order->customer_note,
            'confirmation' => 'Demande reçue. Une validation humaine reste nécessaire ; les conditions commerciales et la disponibilité seront confirmées par notre équipe.',
        ];
    }

    /** @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function line(array $line): array
    {
        $result = Arr::only($line, ['id', 'product_id', 'sku', 'designation', 'original_reference', 'quantity',
            'units_per_package', 'orderable', 'unit_price', 'line_subtotal']);
        $result['pricing'] = isset($line['pricing']) ? $this->pricing($line['pricing']) : null;

        return $result;
    }

    /** @param array<string, mixed> $price
     * @return array<string, mixed>
     */
    private function pricing(array $price): array
    {
        $result = Arr::only($price, ['schema_version', 'policy_version', 'status', 'product_id', 'currency', 'tax_basis',
            'base_unit_price', 'unit_price', 'applied_source', 'customer_discount_percentage', 'calculated_at', 'valid_until', 'fingerprint']);
        $result['promotion'] = isset($price['promotion'])
            ? Arr::only($price['promotion'], ['id', 'title', 'unit_price', 'version', 'starts_at', 'ends_at']) : null;

        return $result;
    }

    /** @param array<string, mixed> $terms
     * @return array<string, mixed>
     */
    private function commercialTerms(array $terms): array
    {
        $result = Arr::only($terms, ['fees', 'currency', 'tax_basis', 'status']);
        $result['discounts'] = isset($terms['discounts']) ? array_map(fn (array $discount): array => [
            'line_id' => $discount['line_id'], 'pricing' => $this->pricing($discount['pricing']),
        ], $terms['discounts']) : null;

        return $result;
    }
}
