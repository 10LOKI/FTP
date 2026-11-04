<?php

namespace App\Models;

use App\Services\ProductReferenceNormalizer;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $hidden = [
        'net_purchase_price', 'sale_price', 'original_reference_normalized',
        'original_reference_search_key', 'original_reference_exact_key',
    ];

    protected $fillable = [
        'sku', 'designation', 'original_reference', 'part_brand', 'category',
        'hs_code', 'net_weight', 'weight_unit', 'images',
    ];

    protected function casts(): array
    {
        return ['images' => 'array', 'net_weight' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            if ($product->isDirty('original_reference') || $product->original_reference_search_key === null) {
                $keys = ProductReferenceNormalizer::attributes($product->original_reference);
                $product->original_reference_normalized = $keys['normalized_reference'];
                $product->original_reference_search_key = $keys['reference_search_key'];
                $product->original_reference_exact_key = $keys['reference_exact_key'];
            }
        });
    }
}
