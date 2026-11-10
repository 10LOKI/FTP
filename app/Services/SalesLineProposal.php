<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SalesLineProposal
{
    public function __construct(private readonly CustomerPricing $pricing) {}

    /** @param list<array{product_id: int|string, quantity: int|string, units_per_package: int|string}> $requested
     * @return array<string, mixed>
     */
    public function calculate(int $customerId, array $requested): array
    {
        $profile = CustomerProfile::query()->where('user_id', $customerId)->lockForUpdate()->first();
        abort_unless($profile !== null && $profile->approval === 'APPROUVE', 403);
        $products = Product::query()->whereIn('id', array_column($requested, 'product_id'))->orderBy('id')->lockForUpdate()->get();
        $quotes = $this->pricing->quotes($products->filter(fn (Product $product): bool => $product->publication === 'published')->values(), User::query()->findOrFail($customerId));
        $lines = [];
        $seen = [];
        $subtotal = '0.00';
        foreach ($requested as $input) {
            $product = $products->firstWhere('id', (int) $input['product_id']);
            abort_unless($product !== null && $product->publication === 'published', 422, 'Produit indisponible.');
            $units = (int) $input['units_per_package'];
            $quantity = (int) $input['quantity'];
            $key = $product->id.':'.$units;
            abort_if(isset($seen[$key]), 422, 'Regroupez le même produit et conditionnement dans une seule ligne.');
            $seen[$key] = true;
            abort_unless($units === 1 || DB::table('product_barcodes')->where('product_id', $product->id)->where('units_per_package', $units)->exists(), 422, 'Conditionnement invalide.');
            $quote = $quotes[$product->id];
            abort_unless($quote['status'] === 'ready' && $quote['unit_price'] !== null, 422, 'Tarif indisponible.');
            abort_unless($quote['currency'] === 'MAD' && $quote['tax_basis'] === 'TTC', 422, 'Les conditions MAD TTC doivent être configurées par le responsable habilité.');
            $total = bcmul($quote['unit_price'], bcmul((string) $quantity, (string) $units, 0), 2);
            $subtotal = bcadd($subtotal, $total, 2);
            abort_if(bccomp($subtotal, '9999999999999999999999.99', 2) > 0, 422, 'Montant trop élevé pour cette proposition.');
            $lines[] = ['product_id' => (string) $product->id, 'sku' => $product->sku,
                'designation' => $product->designation, 'original_reference' => $product->original_reference,
                'quantity' => $quantity, 'units_per_package' => $units, 'unit_price' => $quote['unit_price'],
                'line_subtotal' => $total, 'pricing' => $quote];
        }
        $stable = $lines;
        foreach ($stable as &$line) {
            unset($line['pricing']['calculated_at']);
        }
        unset($line);

        return ['lines' => $lines, 'estimated_subtotal' => $subtotal, 'currency' => 'MAD', 'tax_basis' => 'TTC',
            'pricing_token' => hash('sha256', json_encode($stable, JSON_THROW_ON_ERROR)), 'requires_agreement' => true];
    }
}
