<?php

namespace App\Http\Resources;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerProfile */
class CustomerApprovalDecisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'customerId' => $this->id,
            'approval' => $this->approval,
            'customerCode' => $this->customer_code,
            'version' => $this->version,
            'decidedBy' => $this->decided_by,
            'decidedAt' => $this->decided_at->toISOString(),
            'reason' => $this->decision_reason,
        ];
    }
}
