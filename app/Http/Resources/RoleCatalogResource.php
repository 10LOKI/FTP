<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleCatalogResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'label' => $this->label];
    }
}
