<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmployeeAccessDelivery extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'created_by', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'attempts' => 'integer', 'requested_access_version' => 'integer'];
    }
}
