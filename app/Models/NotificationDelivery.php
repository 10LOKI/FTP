<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['trigger_event_id', 'recipient_id', 'type', 'channel', 'payload'];

    protected $hidden = ['payload', 'recipient_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'last_attempt_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
