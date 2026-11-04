<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'product_id' => (string) $this->product_id,
            'title' => $this->title, 'kind' => $this->kind, 'value' => $this->value,
            'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at->toIso8601String(),
            'timezone' => $this->timezone,
            'starts_local' => $this->starts_at->setTimezone($this->timezone)->format('Y-m-d\TH:i:s'),
            'ends_local' => $this->ends_at->setTimezone($this->timezone)->format('Y-m-d\TH:i:s'),
            'product_label' => $this->product->sku.' — '.$this->product->designation, 'state' => $this->state, 'version' => $this->version,
            'created_by' => (string) $this->created_by, 'updated_by' => (string) $this->updated_by,
        ];
    }
}
