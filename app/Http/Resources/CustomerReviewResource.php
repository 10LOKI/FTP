<?php

namespace App\Http\Resources;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerProfile */
class CustomerReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'customerId' => $this->id,
            'type' => $this->type,
            'approval' => $this->approval,
            'version' => $this->version,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'company_name' => $this->company_name,
            'responsible_name' => $this->responsible_name,
            'registered_at' => $this->created_at->toISOString(),
        ];
    }
}
