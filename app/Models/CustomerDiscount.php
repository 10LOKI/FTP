<?php

namespace App\Models;

use Database\Factories\CustomerDiscountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDiscount extends Model
{
    /** @use HasFactory<CustomerDiscountFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['percentage'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['percentage' => 'decimal:2', 'version' => 'integer'];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }
}
