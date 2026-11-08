<?php

namespace App\Models;

use Database\Factories\WarehouseLocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseLocation extends Model
{
    /** @use HasFactory<WarehouseLocationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
