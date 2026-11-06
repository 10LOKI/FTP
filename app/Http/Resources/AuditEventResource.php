<?php

namespace App\Http\Resources;

use App\Services\AuditSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'schema_version' => $this->schema_version,
            'actor_id' => $this->actor_id === null ? null : (string) $this->actor_id,
            'actor_kind' => $this->actor_kind, 'role_context' => $this->role_context ?? [], 'role_context_available' => $this->role_context !== null,
            'request_id' => $this->request_id, 'action' => $this->action,
            'object_type' => $this->object_type, 'object_id' => $this->object_id,
            'result' => $this->result, 'reason' => $this->reason,
            'changes' => AuditSanitizer::changes($this->changes),
            'context' => $this->context, 'occurred_at' => $this->occurred_at->utc()->toIso8601String(),
        ];
    }
}
