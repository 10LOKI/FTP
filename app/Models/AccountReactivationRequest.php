<?php

namespace App\Models;

use Database\Factories\AccountReactivationRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountReactivationRequest extends Model
{
    /** @use HasFactory<AccountReactivationRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['user_id', 'active_user_id', 'suspension_version', 'request_reason'];

    protected $hidden = ['decision_reason'];

    protected function casts(): array
    {
        return ['suspension_version' => 'integer', 'version' => 'integer',
            'reviewed_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
