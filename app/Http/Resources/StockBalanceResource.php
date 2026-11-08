<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'warehouse_id' => $this->warehouse_id, 'product_id' => (string) $this->product_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): array => ['code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'product' => $this->whenLoaded('product', fn (): array => ['sku' => $this->product->sku, 'designation' => $this->product->designation]),
            'location_id' => $this->location_id, 'location' => $this->whenLoaded('location', fn (): ?array => $this->location === null ? null : ['code' => $this->location->code, 'aisle' => $this->location->aisle, 'shelf' => $this->location->shelf, 'level' => $this->location->level]),
            'physical' => $this->physical, 'reserved' => $this->reserved, 'available' => $this->physical - $this->reserved,
            'control' => $this->control, 'rejected' => $this->rejected, 'version' => $this->version,
            'threshold_quantity' => $this->threshold_quantity, 'threshold_basis' => $this->threshold_basis,
        ];
    }
}
