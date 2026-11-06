<?php

namespace App\Models;

use App\Services\AuditContext;
use App\Services\AuditSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'request_id', 'action', 'object_type', 'object_id',
        'result', 'reason', 'changes', 'occurred_at', 'actor_kind', 'role_context', 'context', 'schema_version',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'role_context' => 'array', 'context' => 'array', 'schema_version' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->schema_version = 1;
            $event->setAttribute('changes', AuditSanitizer::changes($event->getAttribute('changes')));
            $context = app(AuditContext::class);
            $event->actor_kind = $event->actor_id !== null ? 'account'
                : (! $context->active || str_starts_with($event->action, 'notification.') || $event->action === 'employee.access.delivery' ? 'system' : 'visitor');
            $event->role_context = $event->actor_id === null ? []
                : User::find($event->actor_id)?->roles()->orderBy('code')->pluck('code')->all() ?? [];
            $event->context = $context->active ? [
                'method' => request()->method(), 'route' => request()->route()?->getName() ?? request()->route()?->uri(), 'http_request_id' => $context->requestId,
            ] : ['origin' => 'system'];
        });
        static::created(function (self $event): void {
            $context = app(AuditContext::class);
            if ($context->active) {
                $context->events++;
                if ($event->result !== 'SUCCESS') {
                    $context->failures[] = $event->getAttributes();
                }
            }
        });
        static::updating(function (): void {
            throw new LogicException('Audit events cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('Audit events cannot be deleted.');
        });
    }

    public static function recordRegistration(
        string $requestId,
        string $result,
        ?CustomerProfile $profile = null,
        ?string $reason = null,
    ): self {
        return self::create([
            'actor_id' => null,
            'request_id' => $requestId,
            'action' => 'customer.registration',
            'object_type' => 'customer_profile',
            'object_id' => $profile?->id,
            'result' => $result,
            'reason' => $reason,
            'changes' => $profile === null ? null : [
                'before' => null,
                'after' => ['type' => $profile->type, 'approval' => $profile->approval],
            ],
            'occurred_at' => now('UTC'),
        ]);
    }
}
