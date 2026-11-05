<?php

namespace App\Services;

use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerPricing
{
    /** @param Collection<int, Product> $products
     * @return array<int, array<string, mixed>>
     */
    public function quotes(Collection $products, User $actor): array
    {
        $profile = CustomerProfile::query()->where('user_id', $actor->id)->where('approval', 'APPROUVE')->first();
        abort_unless($profile !== null, 403);
        PricingPolicy::requireApproved();
        $at = CarbonImmutable::now('UTC');
        $settings = DB::table('pricing_settings')->where('id', 1)->firstOrFail();
        $discount = CustomerDiscount::query()->where('customer_profile_id', $profile->id)->first();
        $promotions = Promotion::query()->whereIn('product_id', $products->pluck('id'))->where('state', 'enabled')
            ->where('ends_at', '>', $at)->orderBy('starts_at')->get()->groupBy('product_id');
        $result = [];
        foreach ($products as $product) {
            abort_unless($product->publication === 'published', 404);
            $windows = $promotions->get($product->id, collect());
            $active = $windows->filter(fn (Promotion $promotion): bool => $promotion->starts_at->lessThanOrEqualTo($at));
            $nextStart = $windows->first(fn (Promotion $promotion): bool => $promotion->starts_at->greaterThan($at))?->starts_at;
            $baseValue = $product->getRawOriginal('sale_price');
            $base = $baseValue === null ? null : PriceAmount::minorUnits((string) $baseValue);
            $unit = $base;
            $source = 'catalogue';
            $offer = null;
            $validUntil = $nextStart?->toIso8601String();
            $status = $base === null ? 'missing_base_price' : ($active->count() > 1 ? 'conflicting_promotions' : 'ready');
            if ($status === 'ready') {
                $promotion = $active->first();
                if ($promotion !== null) {
                    $offerPrice = $promotion->kind === 'fixed_price' ? PriceAmount::minorUnits($promotion->value) : PriceAmount::discounted($base, $promotion->value);
                    if ($offerPrice < $base) {
                        $unit = $offerPrice;
                        $source = 'promotion';
                        $validUntil = $promotion->ends_at->toIso8601String();
                        $offer = ['id' => (string) $promotion->id, 'title' => $promotion->title, 'unit_price' => PriceAmount::decimal($offerPrice), 'version' => $promotion->version,
                            'starts_at' => $promotion->starts_at->toIso8601String(), 'ends_at' => $promotion->ends_at->toIso8601String()];
                    }
                }
                if ($discount !== null) {
                    $customerPrice = PriceAmount::discounted($base, $discount->percentage);
                    if ($customerPrice < $unit) {
                        $unit = $customerPrice;
                        $source = 'customer_discount';
                    }
                }
            } else {
                $unit = null;
            }
            $price = [
                'schema_version' => 1, 'policy_version' => config('pricing.policy_version'), 'status' => $status,
                'product_id' => (string) $product->id, 'currency' => $settings->currency, 'tax_basis' => $settings->tax_basis,
                'base_unit_price' => $base === null ? null : PriceAmount::decimal($base), 'unit_price' => $unit === null ? null : PriceAmount::decimal($unit),
                'applied_source' => $source, 'promotion' => $offer, 'customer_discount_percentage' => $discount?->percentage ?? '0.00',
                'calculated_at' => $at->toIso8601String(), 'valid_until' => $validUntil,
            ];
            $price['fingerprint'] = hash('sha256', json_encode([
                $product->id, $base, $unit, $status, $settings->currency, $settings->tax_basis, $settings->version,
                config('pricing.policy_version'), $offer, $discount?->id, $discount?->version,
            ], JSON_THROW_ON_ERROR));
            $result[$product->id] = $price;
        }

        return $result;
    }

    public function refreshAfterMs(): int
    {
        $at = CarbonImmutable::now('UTC');
        $row = DB::table('promotions')->join('products', 'products.id', '=', 'promotions.product_id')
            ->where('products.publication', 'published')->where('promotions.state', 'enabled')->where('ends_at', '>', $at)
            ->selectRaw('MIN(CASE WHEN starts_at > ? THEN starts_at ELSE ends_at END) as next_change', [$at])->first();
        if ($row->next_change === null) {
            return 60000;
        }

        return max(1000, min(60000, (int) $at->diffInMilliseconds(CarbonImmutable::parse($row->next_change, 'UTC'), false) + 150));
    }

    /** @param Collection<int, Product> $products */
    public function annotate(Collection $products, ?User $actor): void
    {
        if ($actor === null || $products->isEmpty()) {
            return;
        }
        foreach ($this->quotes($products, $actor) as $id => $price) {
            $product = $products->firstWhere('id', $id);
            $product->setAttribute('pricing', $price);
            $product->setAttribute('sale_price', $price['unit_price']);
        }
    }
}
