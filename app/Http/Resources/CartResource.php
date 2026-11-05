<?php

namespace App\Http\Resources;

use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'version' => $this->resource['version'],
            'lines' => $this->resource['lines'],
            'pricing_status' => $this->resource['pricing_status'],
            'totals' => $this->resource['totals'],
            'pricing_token' => CartService::pricingToken($this->resource),
            'checkout_ready' => $this->resource['lines'] !== [] && $this->resource['pricing_status'] === 'customer_estimate',
        ];
    }
}
