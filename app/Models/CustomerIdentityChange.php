<?php

namespace App\Models;

use Database\Factories\CustomerIdentityChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerIdentityChange extends Model
{
    /** @use HasFactory<CustomerIdentityChangeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['customer_profile_id', 'active_customer_profile_id', 'profile_version', 'name', 'email', 'type', 'company_name', 'responsible_name'];

    protected $hidden = ['decision_reason'];

    protected function casts(): array
    {
        return ['verification_revision' => 'integer', 'profile_version' => 'integer', 'version' => 'integer', 'reviewed_at' => 'immutable_datetime', 'email_verified_at' => 'immutable_datetime', 'verification_expires_at' => 'immutable_datetime'];
    }
}
