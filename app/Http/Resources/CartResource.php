<?php

namespace App\Http\Resources;

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
            'checkout_ready' => false,
        ];
    }
}
