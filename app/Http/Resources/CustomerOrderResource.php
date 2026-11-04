<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => (string) $order->id, 'number' => $order->number, 'version' => (int) $order->version,
            'status' => $order->status, 'submitted_at' => $order->created_at,
            'contact' => json_decode($order->contact, true, 512, JSON_THROW_ON_ERROR),
            'delivery_address' => json_decode($order->delivery_address, true, 512, JSON_THROW_ON_ERROR),
            'billing_address' => json_decode($order->billing_address, true, 512, JSON_THROW_ON_ERROR),
            'lines' => json_decode($order->lines, true, 512, JSON_THROW_ON_ERROR),
            'pricing_status' => $order->pricing_status,
            'commercial_terms' => $order->commercial_snapshot === null
                ? ['fees' => null, 'discounts' => null, 'currency' => null, 'status' => 'pending_human_review']
                : json_decode($order->commercial_snapshot, true, 512, JSON_THROW_ON_ERROR),
            'totals' => ['catalogue_subtotal' => bcadd((string) $order->catalogue_subtotal, '0', 2)],
            'customer_note' => $order->customer_note,
            'confirmation' => 'Demande reçue. Une validation humaine reste nécessaire ; les conditions commerciales et la disponibilité seront confirmées par notre équipe.',
        ];
    }
}
