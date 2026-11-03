<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $hidden = ['net_purchase_price', 'sale_price'];

    protected $fillable = [
        'sku', 'designation', 'original_reference', 'part_brand', 'category',
        'hs_code', 'net_weight', 'weight_unit', 'images',
    ];

    protected function casts(): array
    {
        return ['images' => 'array', 'net_weight' => 'decimal:4'];
    }
}
