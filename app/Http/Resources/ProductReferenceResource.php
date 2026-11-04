<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReferenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'CROISEE',
            'reference' => $this->reference,
            'normalized_reference' => $this->normalized_reference,
            'brand' => $this->brand,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'version' => $this->version,
        ];
    }
}
