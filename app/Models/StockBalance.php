<?php

namespace App\Models;

use Database\Factories\StockBalanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    /** @use HasFactory<StockBalanceFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['physical' => 0, 'reserved' => 0, 'control' => 0, 'rejected' => 0, 'version' => 0];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    protected function casts(): array
    {
        return ['physical' => 'integer', 'reserved' => 'integer', 'control' => 'integer', 'rejected' => 'integer', 'version' => 'integer', 'threshold_quantity' => 'integer'];
    }
}
