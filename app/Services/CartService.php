<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * @phpstan-type CartLine array{id: string, product_id: string, sku: ?string, designation: ?string, original_reference: ?string, quantity: int, units_per_package: int, orderable: bool, unit_price: ?string, line_subtotal: ?string, pricing: ?array<string, mixed>}
 * @phpstan-type CartState array{id: ?string, version: int, lines: list<CartLine>, pricing_status: string, totals: ?array{catalogue_subtotal: string}}
 */
class CartService
{
    public function __construct(private readonly CustomerPricing $pricing) {}

    /** @return CartState */
    public function snapshot(int $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $this->assertCustomer($userId);
            $cart = DB::table('carts')->where('user_id', $userId)->lockForUpdate()->first();

            return $this->serialize($cart);
        }, 3);
    }

    /** @param CartState $cart */
    public static function pricingToken(array $cart): string
    {
        foreach ($cart['lines'] as &$line) {
            unset($line['pricing']['calculated_at']);
        }
        unset($line);

        return hash('sha256', json_encode($cart, JSON_THROW_ON_ERROR));
    }

    /** @return CartState */
    public function putLine(int $userId, int $productId, int $quantity, int $unitsPerPackage, int $expectedVersion, string $requestId): array
    {
        return DB::transaction(function () use ($userId, $productId, $quantity, $unitsPerPackage, $expectedVersion, $requestId): array {
            $this->assertCustomer($userId);
            $cart = $this->lockedCart($userId, $expectedVersion);
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            abort_unless($product !== null && $product->publication === 'published', 404);

            if ($unitsPerPackage !== 1 && ! DB::table('product_barcodes')
                ->where('product_id', $productId)->where('units_per_package', $unitsPerPackage)->exists()) {
                throw ValidationException::withMessages(['units_per_package' => 'Ce conditionnement ne correspond pas au produit.']);
            }

            $line = DB::table('cart_lines')->where('cart_id', $cart->id)
                ->where('product_id', $productId)->where('units_per_package', $unitsPerPackage)->first();

            if ($line !== null && (int) $line->quantity === $quantity) {
                $this->audit($cart, $userId, $requestId, 'cart.line_unchanged', null);

                return $this->serialize($cart);
            }

            if ($line === null) {
                DB::table('cart_lines')->insert([
                    'cart_id' => $cart->id, 'product_id' => $productId,
                    'quantity' => $quantity, 'units_per_package' => $unitsPerPackage,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('cart_lines')->where('id', $line->id)->update(['quantity' => $quantity, 'updated_at' => now()]);
            }

            $this->audit($cart, $userId, $requestId, 'cart.line_updated', [
                'product_id' => $productId, 'units_per_package' => $unitsPerPackage,
                'before_quantity' => $line === null ? null : (int) $line->quantity, 'after_quantity' => $quantity,
            ]);

            return $this->advanceVersion($cart);
        }, 3);
    }

    /** @return CartState */
    public function removeLine(int $userId, int $lineId, int $expectedVersion, string $requestId): array
    {
        return DB::transaction(function () use ($userId, $lineId, $expectedVersion, $requestId): array {
            $this->assertCustomer($userId);
            $cart = DB::table('carts')->where('user_id', $userId)->lockForUpdate()->first();
            abort_unless($cart !== null, 404);
            $this->assertVersion($cart, $expectedVersion);
            $deleted = DB::table('cart_lines')->where('cart_id', $cart->id)->where('id', $lineId)->delete();
            abort_unless($deleted === 1, 404);
            $this->audit($cart, $userId, $requestId, 'cart.line_removed', ['line_id' => $lineId]);

            return $this->advanceVersion($cart);
        }, 3);
    }

    private function lockedCart(int $userId, int $expectedVersion): stdClass
    {
        DB::table('carts')->insertOrIgnore([
            'user_id' => $userId, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cart = DB::table('carts')->where('user_id', $userId)->lockForUpdate()->first();
        $this->assertVersion($cart, $expectedVersion);

        return $cart;
    }

    private function assertCustomer(int $userId): void
    {
        $profile = CustomerProfile::query()->where('user_id', $userId)->lockForUpdate()->first();
        abort_unless($profile !== null && $profile->approval === 'APPROUVE', 403);
    }

    private function audit(stdClass $cart, int $userId, string $requestId, string $action, ?array $changes): void
    {
        AuditEvent::create([
            'actor_id' => $userId, 'request_id' => $requestId, 'action' => $action,
            'object_type' => 'cart', 'object_id' => (string) $cart->id, 'result' => 'SUCCESS',
            'changes' => $changes, 'occurred_at' => now('UTC'),
        ]);
    }

    private function assertVersion(stdClass $cart, int $expectedVersion): void
    {
        abort_if((int) $cart->version !== $expectedVersion, 409, 'Le panier a changé. Rechargez-le avant de réessayer.');
    }

    /** @return CartState */
    private function advanceVersion(stdClass $cart): array
    {
        DB::table('carts')->where('id', $cart->id)->increment('version', 1, ['updated_at' => now()]);
        $cart->version++;

        return $this->serialize($cart);
    }

    /** @return CartState */
    private function serialize(?stdClass $cart): array
    {
        $rows = $cart === null ? collect() : DB::table('cart_lines')
            ->join('products', 'products.id', '=', 'cart_lines.product_id')
            ->where('cart_lines.cart_id', $cart->id)->orderBy('cart_lines.id')->lockForUpdate()
            ->get(['cart_lines.id', 'cart_lines.product_id', 'cart_lines.quantity', 'cart_lines.units_per_package',
                'products.sku', 'products.designation', 'products.original_reference', 'products.publication', 'products.sale_price']);
        $products = Product::query()->whereIn('id', $rows->pluck('product_id'))->where('publication', 'published')
            ->orderBy('id')->lockForUpdate()->get();
        $quotes = [];
        $pricingUnavailable = false;
        try {
            if ($products->isNotEmpty()) {
                $quotes = $this->pricing->quotes($products, User::query()->findOrFail($cart->user_id));
            }
        } catch (ServiceUnavailableHttpException) {
            $pricingUnavailable = true;
        } catch (ValidationException $exception) {
            if (! array_key_exists('state', $exception->errors())) {
                throw $exception;
            }
            $pricingUnavailable = true;
        }
        $lines = $rows->map(fn (stdClass $line): array => [
            'id' => (string) $line->id,
            'product_id' => (string) $line->product_id,
            'sku' => $line->publication === 'published' ? $line->sku : null,
            'designation' => $line->publication === 'published' ? $line->designation : null,
            'original_reference' => $line->publication === 'published' ? $line->original_reference : null,
            'quantity' => (int) $line->quantity,
            'units_per_package' => (int) $line->units_per_package,
            'orderable' => $line->publication === 'published',
            'unit_price' => $quotes[$line->product_id]['unit_price'] ?? null,
            'line_subtotal' => ($quotes[$line->product_id]['unit_price'] ?? null) !== null
                ? bcmul($quotes[$line->product_id]['unit_price'], bcmul((string) $line->quantity, (string) $line->units_per_package, 0), 2) : null,
            'pricing' => $quotes[$line->product_id] ?? null,
        ]);

        $priced = $lines->every(fn (array $line): bool => $line['line_subtotal'] !== null);
        $subtotal = $priced ? $lines->reduce(fn (string $sum, array $line): string => bcadd($sum, $line['line_subtotal'], 2), '0.00') : null;

        return ['id' => $cart === null ? null : (string) $cart->id,
            'version' => (int) ($cart->version ?? 0), 'lines' => $lines->all(),
            'pricing_status' => $pricingUnavailable ? 'pricing_unavailable' : ($priced ? 'customer_estimate' : 'price_unavailable'),
            'totals' => $priced ? ['catalogue_subtotal' => $subtotal] : null];
    }
}
