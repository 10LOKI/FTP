<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'request_id', 'action', 'object_type', 'object_id',
        'result', 'reason', 'changes', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
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
