<?php

namespace Tests\Feature;

use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartPricingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
        $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00Z'));
    }

    /** @return array{CustomerProfile, Product, array<string, mixed>} */
    private function pricedCart(): array
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        CustomerDiscount::factory()->for($profile)->create(['percentage' => '10.00']);
        Promotion::factory()->for($product)->enabled()->create([
            'kind' => 'fixed_price', 'value' => '80.00',
            'starts_at' => now('UTC')->subHour(), 'ends_at' => now('UTC')->addHour(),
        ]);
        $cart = $this->putJson('/api/v1/me/cart/products/'.$product->id,
            ['quantity' => 2, 'units_per_package' => 1, 'expected_version' => 0])->assertOk()->json('data');

        return [$profile, $product, $cart];
    }

    /** @return array<string, mixed> */
    private function submission(array $cart): array
    {
        $address = ['address' => 'Adresse de test', 'city' => 'Rabat', 'country' => 'Maroc'];

        return [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => $cart['version'],
            'pricing_token' => $cart['pricing_token'],
            'contact' => ['name' => 'Client', 'email' => 'client@example.test', 'phone' => '0000000000'],
            'delivery_address' => $address, 'billing_address' => $address,
        ];
    }

    public function test_cart_uses_the_real_catalogue_customer_quote_and_snapshots_the_promotion(): void
    {
        [$profile, $product, $cart] = $this->pricedCart();
        $quote = $this->getJson('/api/v1/me/products/'.$product->id.'/price')->assertOk()->json('data');
        $this->assertSame($quote['fingerprint'], $cart['lines'][0]['pricing']['fingerprint']);
        $this->assertSame('80.00', $cart['lines'][0]['unit_price']);
        $this->assertSame('160.00', $cart['totals']['catalogue_subtotal']);
        $order = $this->postJson('/api/v1/me/orders', $this->submission($cart))->assertCreated()
            ->assertJsonPath('data.commercial_terms.currency', 'MAD')
            ->assertJsonPath('data.lines.0.pricing.applied_source', 'promotion')
            ->assertJsonPath('data.lines.0.pricing.customer_discount_percentage', '10.00')->json('data');
        Promotion::query()->where('product_id', $product->id)->update(['state' => 'disabled']);
        CustomerDiscount::query()->where('customer_profile_id', $profile->id)->update(['percentage' => '30.00', 'version' => 2]);
        $this->getJson('/api/v1/me/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.lines.0.unit_price', '80.00')
            ->assertJsonPath('data.lines.0.pricing.applied_source', 'promotion')
            ->assertJsonPath('data.commercial_terms.discounts.0.pricing.fingerprint', $quote['fingerprint']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => '100.00']);
    }

    public function test_clock_only_changes_do_not_invalidate_a_quote_but_expiry_requires_review(): void
    {
        [, , $cart] = $this->pricedCart();
        $this->travel(1)->seconds();
        $later = $this->getJson('/api/v1/me/cart')->assertOk()->json('data');
        $this->assertNotSame($cart['lines'][0]['pricing']['calculated_at'], $later['lines'][0]['pricing']['calculated_at']);
        $this->assertSame($cart['pricing_token'], $later['pricing_token']);
        $this->travel(1)->hours();
        $this->postJson('/api/v1/me/orders', $this->submission($cart))->assertConflict();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
        $fresh = $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.lines.0.unit_price', '90.00')->json('data');
        $this->postJson('/api/v1/me/orders', $this->submission($fresh))->assertCreated()
            ->assertJsonPath('data.totals.catalogue_subtotal', '180.00')
            ->assertJsonPath('data.lines.0.pricing.applied_source', 'customer_discount');
    }

    public function test_customer_discount_and_currency_version_changes_require_revalidation(): void
    {
        [$profile, , $cart] = $this->pricedCart();
        CustomerDiscount::query()->where('customer_profile_id', $profile->id)->update(['percentage' => '30.00', 'version' => 2]);
        $this->postJson('/api/v1/me/orders', $this->submission($cart))->assertConflict();
        $fresh = $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.lines.0.unit_price', '70.00')->json('data');
        DB::table('pricing_settings')->where('id', 1)->update(['currency' => 'EUR', 'version' => 2]);
        $this->postJson('/api/v1/me/orders', $this->submission($fresh))->assertConflict();
        $fresh = $this->getJson('/api/v1/me/cart')->assertOk()->json('data');
        $this->postJson('/api/v1/me/orders', $this->submission($fresh))->assertCreated()
            ->assertJsonPath('data.commercial_terms.currency', 'EUR')
            ->assertJsonPath('data.totals.catalogue_subtotal', '140.00');
    }

    public function test_conflicting_offers_fail_closed_and_never_consume_cart(): void
    {
        [, $product, $cart] = $this->pricedCart();
        Promotion::factory()->for($product)->enabled()->create();
        $this->getJson('/api/v1/me/cart')->assertOk()
            ->assertJsonPath('data.lines.0.pricing.status', 'conflicting_promotions')
            ->assertJsonPath('data.lines.0.unit_price', null)->assertJsonPath('data.checkout_ready', false);
        $this->postJson('/api/v1/me/orders', $this->submission($cart))->assertUnprocessable();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
    }

    public function test_unapproved_policy_is_not_bypassed_by_cart_or_order_services(): void
    {
        [, , $cart] = $this->pricedCart();
        config(['pricing.approved' => false]);
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.pricing_status', 'pricing_unavailable')
            ->assertJsonPath('data.checkout_ready', false)->assertJsonPath('data.totals', null);
        $this->postJson('/api/v1/me/orders', $this->submission($cart))->assertUnprocessable();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
    }
}
