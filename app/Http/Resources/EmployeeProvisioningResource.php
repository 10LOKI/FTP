<?php

namespace App\Http\Resources;

use App\Models\EmployeeAccessDelivery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeAccessDelivery */
class EmployeeProvisioningResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = User::findOrFail($this->user_id);

        return [
            'deliveryId' => $this->id, 'userId' => $this->user_id,
            'status' => $this->status, 'attempts' => $this->attempts,
            'expiresAt' => $this->expires_at->toISOString(),
            'sentAt' => $this->sent_at?->toISOString(), 'lastErrorCode' => $this->last_error_code,
            'mustChangePassword' => $user->must_change_password,
            'accessVersion' => $user->access_version,
        ];
    }
}
