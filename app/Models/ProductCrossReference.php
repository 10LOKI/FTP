<?php

namespace App\Models;

use App\Services\ProductReferenceNormalizer;
use Database\Factories\ProductCrossReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCrossReference extends Model
{
    /** @use HasFactory<ProductCrossReferenceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $attributes = ['version' => 1];

    protected $fillable = ['brand', 'reference'];

    protected $hidden = ['reference_search_key', 'reference_exact_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::saving(function (ProductCrossReference $reference): void {
            foreach (ProductReferenceNormalizer::attributes($reference->reference) as $field => $value) {
                $reference->setAttribute($field, $value);
            }
        });
    }
}
