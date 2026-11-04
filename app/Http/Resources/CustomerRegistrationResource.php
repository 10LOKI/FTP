<?php

namespace App\Http\Resources;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerProfile */
class CustomerRegistrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'customerId' => $this->id,
            'type' => $this->type,
            'approval' => $this->approval,
            'message' => 'Votre demande est en attente d’approbation. L’accès aux commandes reste bloqué.',
        ];
    }
}
