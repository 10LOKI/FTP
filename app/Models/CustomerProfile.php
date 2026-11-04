<?php

namespace App\Models;

use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'type', 'phone', 'address', 'city', 'country', 'company_name', 'responsible_name',
    ];

    protected $hidden = ['internal_notes', 'decision_reason'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
