<?php

namespace App\Http\Resources;

use App\Models\AccountReactivationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountReactivationRequest */
class AccountReactivationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'version' => $this->version,
            'requiresFreshLogin' => $this->status === 'APPROVED',
            'requestedAt' => $this->created_at->toISOString(),
            'reviewedAt' => $this->reviewed_at?->toISOString(), 'completedAt' => $this->completed_at?->toISOString()];
    }
}
