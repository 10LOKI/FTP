<?php

namespace Tests\Feature;

use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartCustomerPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
    }

    public function test_real_customer_quote_is_applied_to_canonical_cart_lines(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        CustomerDiscount::factory()->for($profile)->create(['percentage' => '10.00']);
        Promotion::factory()->for($product)->enabled()->create(['kind' => 'fixed_price', 'value' => '80.00']);
        $this->putJson('/api/v1/me/cart/products/'.$product->id,
            ['quantity' => 2, 'units_per_package' => 1, 'expected_version' => 0])->assertOk()
            ->assertJsonPath('data.lines.0.unit_price', '80.00')->assertJsonPath('data.lines.0.pricing.currency', 'MAD')
            ->assertJsonPath('data.lines.0.pricing.applied_source', 'promotion')->assertJsonPath('data.totals.catalogue_subtotal', '160.00');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => '100.00']);
    }

    public function test_unavailable_pricing_does_not_prevent_removing_cart_lines(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        $this->putJson('/api/v1/me/cart/products/'.$product->id,
            ['quantity' => 1, 'units_per_package' => 1, 'expected_version' => 0])->assertOk();
        config(['pricing.approved' => false]);
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.pricing_status', 'pricing_unavailable')
            ->assertJsonPath('data.lines.0.unit_price', null);
        $this->deleteJson('/api/v1/me/cart/lines/'.DB::table('cart_lines')->value('id'), ['expected_version' => 1])
            ->assertOk()->assertJsonCount(0, 'data.lines');
    }
}
