<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductReferenceSearch
{
    /** @param Builder<Product> $products
     * @return Builder<Product>
     */
    public function apply(Builder $products, string $reference): Builder
    {
        $key = ProductReferenceNormalizer::searchKey($reference);
        $exactKey = hash('sha256', $reference);
        $original = DB::table('products')->where('original_reference_search_key', $key)
            ->selectRaw('id as product_id, CASE WHEN original_reference_exact_key = ? THEN 0 ELSE 1 END as match_priority', [$exactKey]);
        $crossReferences = DB::table('product_cross_references')
            ->where('reference_search_key', $key)->whereNotNull('verified_at')
            ->selectRaw('product_id, CASE WHEN reference_exact_key = ? THEN 0 ELSE 1 END as match_priority', [$exactKey]);
        $matches = DB::query()->fromSub($original->unionAll($crossReferences), 'reference_candidates')
            ->select('product_id')->selectRaw('MIN(match_priority) as match_priority')->groupBy('product_id');

        return $products->joinSub($matches, 'reference_matches', 'products.id', '=', 'reference_matches.product_id')
            ->orderBy('reference_matches.match_priority');
    }

    /** @param Collection<int, Product> $products */
    public function annotate(Collection $products, string $reference): void
    {
        $key = ProductReferenceNormalizer::searchKey($reference);
        $references = DB::table('product_cross_references')->whereIn('product_id', $products->pluck('id'))
            ->where('reference_search_key', $key)->whereNotNull('verified_at')->orderBy('id')
            ->get(['product_id', 'reference', 'brand'])->groupBy('product_id');

        foreach ($products as $product) {
            $matches = [];
            if (ProductReferenceNormalizer::searchKey($product->original_reference) === $key) {
                $matches[] = ['type' => 'ORIGINALE', 'reference' => $product->original_reference, 'brand' => $product->part_brand];
            }
            foreach ($references->get($product->id, []) as $match) {
                $matches[] = ['type' => 'CROISEE', 'reference' => $match->reference, 'brand' => $match->brand];
            }
            if ($matches === []) {
                $product->setAttribute('matched_reference', null);

                continue;
            }
            $selected = collect($matches)->first(fn (array $match): bool => $match['reference'] === $reference) ?? $matches[0];
            $product->setAttribute('matched_reference', $selected + [
                'match' => $selected['reference'] === $reference ? 'exact' : 'normalized',
            ]);
        }
    }
}
