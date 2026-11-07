<?php

namespace App\Models;

use Database\Factories\CustomerAddressChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerAddressChange extends Model
{
    /** @use HasFactory<CustomerAddressChangeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['customer_profile_id', 'active_customer_profile_id', 'profile_version', 'address', 'city', 'country'];

    protected $hidden = ['decision_reason'];

    protected function casts(): array
    {
        return ['profile_version' => 'integer', 'version' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }
}
