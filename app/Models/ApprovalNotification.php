<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApprovalNotification extends Model
{
    use HasUuids;

    protected $fillable = ['customer_profile_id', 'approval_event_id', 'recipient_email', 'customer_code', 'portal_url'];

    protected $hidden = ['recipient_email', 'customer_code', 'portal_url'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<ApprovalNotification> $query
     * @return Builder<ApprovalNotification>
     */
    public function scopeReadyForDelivery(Builder $query): Builder
    {
        return $query->whereIn('status', ['PENDING', 'RETRY'])
            ->where(function (Builder $query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now('UTC'));
            });
    }
}
