<?php

namespace App\Http\Resources;

use App\Models\CustomerAddressChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerAddressChange */
class CustomerAddressChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'status' => $this->status, 'version' => $this->version,
            'profileVersion' => $this->profile_version,
            'address' => $this->address, 'city' => $this->city, 'country' => $this->country,
            'requestedAt' => $this->created_at->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
        ];
    }
}
