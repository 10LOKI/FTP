<?php

namespace App\Http\Resources;

use App\Models\CustomerIdentityChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerIdentityChange */
class CustomerIdentityChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'status' => $this->status, 'version' => $this->version,
            'profileVersion' => $this->profile_version,
            'name' => $this->name, 'email' => $this->email, 'type' => $this->type,
            'company_name' => $this->company_name, 'responsible_name' => $this->responsible_name,
            'emailVerified' => $this->email_verified_at !== null,
            'emailVerificationRequired' => $this->verification_expires_at !== null && $this->email_verified_at === null,
            'requestedAt' => $this->created_at->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(),
        ];
    }
}
