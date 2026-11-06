<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class AccessRoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'version' => $this->version,
            'is_protected' => $this->is_protected,
            'permissions' => $this->permissions->sortBy('code')->pluck('code')->values()->all(),
        ];
    }
}
