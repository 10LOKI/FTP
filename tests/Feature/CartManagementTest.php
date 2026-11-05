<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CartManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
    }

    public function test_guest_and_accounts_without_the_customer_gate_cannot_use_cart(): void
    {
        $product = Product::factory()->create(['publication' => 'published']);
        $this->getJson('/api/v1/me/cart')->assertUnauthorized();
        $this->putJson("/api/v1/me/cart/products/{$product->id}", $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/me/cart')->assertForbidden();
        $this->putJson("/api/v1/me/cart/products/{$product->id}", $this->payload())->assertForbidden();
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_cart_is_persistent_and_updates_one_canonical_product_line_without_touching_product(): void
    {
        $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published']);
        ProductCrossReference::factory()->verified()->create(['product_id' => $product->id, 'brand' => 'A', 'reference' => '000123']);
        ProductCrossReference::factory()->verified()->create(['product_id' => $product->id, 'brand' => 'B', 'reference' => 'OTHER-REF']);
        $before = $product->fresh()->getAttributes();
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.version', 0)->assertJsonCount(0, 'data.lines');
        $this->assertDatabaseCount('carts', 0);

        $first = $this->getJson('/api/v1/catalogue/products?reference=000123')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $second = $this->getJson('/api/v1/catalogue/products?reference=OTHER-REF')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->assertSame($first, $second);
        $this->putJson("/api/v1/me/cart/products/$first", $this->payload())->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson("/api/v1/me/cart/products/$second", $this->payload(1, 5))->assertOk()->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.quantity', 5)->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.lines.0.product_id', (string) $product->id);
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertSame($before, $product->fresh()->getAttributes());
    }

    public function test_stale_versions_cannot_overwrite_and_same_quantity_does_not_create_another_line(): void
    {
        $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published']);
        $url = "/api/v1/me/cart/products/{$product->id}";
        $this->putJson($url, $this->payload())->assertOk();
        $this->putJson($url, $this->payload(0, 9))->assertConflict();
        $this->putJson($url, $this->payload(1, 2))->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseHas('cart_lines', ['quantity' => 2]);
        $this->assertDatabaseCount('cart_lines', 1);
    }

    public function test_invalid_quantity_hidden_product_and_unknown_packaging_leave_no_cart(): void
    {
        $this->allowCustomer();
        $product = Product::factory()->create();
        $url = "/api/v1/me/cart/products/{$product->id}";
        $this->putJson($url, $this->payload())->assertNotFound();
        $product->publication = 'published';
        $product->save();
        foreach ([0, -1, '1.5', 1000001] as $quantity) {
            $this->putJson($url, array_replace($this->payload(), ['quantity' => $quantity]))->assertUnprocessable();
        }
        $this->putJson($url, array_replace($this->payload(), ['units_per_package' => 12]))->assertUnprocessable();
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_lines', 0);
    }

    public function test_valid_different_packagings_are_separate_but_belong_to_the_product(): void
    {
        $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published']);
        $other = Product::factory()->create(['publication' => 'published']);
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'barcode' => '000012', 'units_per_package' => 12]);
        $this->putJson("/api/v1/me/cart/products/{$product->id}", $this->payload())->assertOk();
        $this->putJson("/api/v1/me/cart/products/{$product->id}", array_replace($this->payload(1), ['units_per_package' => 12]))
            ->assertOk()->assertJsonCount(2, 'data.lines');
        $this->putJson("/api/v1/me/cart/products/{$other->id}", array_replace($this->payload(2), ['units_per_package' => 12]))->assertUnprocessable();
        $this->assertDatabaseCount('cart_lines', 2);
    }

    public function test_another_customer_cannot_read_or_delete_the_owners_line(): void
    {
        $owner = $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published']);
        $response = $this->putJson("/api/v1/me/cart/products/{$product->id}", $this->payload())->assertOk();
        $lineId = $response->json('data.lines.0.id');
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($other->user);
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonCount(0, 'data.lines');
        $this->deleteJson("/api/v1/me/cart/lines/$lineId", ['expected_version' => 0])->assertNotFound();
        $this->actingAs($owner);
        $this->getJson('/api/v1/me/cart')->assertJsonCount(1, 'data.lines');
        $this->deleteJson("/api/v1/me/cart/lines/$lineId", ['expected_version' => 1])->assertOk()
            ->assertJsonCount(0, 'data.lines')->assertJsonPath('data.version', 2);
        $this->deleteJson("/api/v1/me/cart/lines/$lineId", ['expected_version' => 2])->assertNotFound();
    }

    public function test_payload_cannot_set_owner_prices_or_reservations_and_response_is_allowlisted(): void
    {
        $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published']);
        $product->sale_price = '99.99';
        $product->net_purchase_price = '20.00';
        $product->save();
        $url = "/api/v1/me/cart/products/{$product->id}";
        foreach (['user_id', 'customer_id', 'cart_id', 'price', 'sale_price', 'net_purchase_price', 'total', 'reserved_quantity'] as $field) {
            $this->putJson($url, $this->payload() + [$field => 123])->assertUnprocessable();
        }
        $response = $this->putJson($url, $this->payload())->assertOk()
            ->assertJsonPath('data.pricing_status', 'customer_estimate')->assertJsonPath('data.totals.catalogue_subtotal', '199.98')
            ->assertJsonPath('data.checkout_ready', true);
        $this->assertSame(['id', 'product_id', 'sku', 'designation', 'original_reference', 'quantity', 'units_per_package', 'orderable', 'unit_price', 'line_subtotal', 'pricing'],
            array_keys($response->json('data.lines.0')));
    }

    public function test_archiving_keeps_line_removable_and_gate_revocation_applies_on_next_request(): void
    {
        $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published']);
        $response = $this->putJson("/api/v1/me/cart/products/{$product->id}", $this->payload())->assertOk();
        $lineId = $response->json('data.lines.0.id');
        $product->publication = 'archived';
        $product->save();
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.lines.0.orderable', false)
            ->assertJsonPath('data.lines.0.designation', null);
        $this->putJson("/api/v1/me/cart/products/{$product->id}", $this->payload(1, 3))->assertNotFound();
        CustomerProfile::query()->where('user_id', auth()->id())->update(['approval' => 'REFUSE']);
        $this->getJson('/api/v1/me/cart')->assertForbidden();
        $this->deleteJson("/api/v1/me/cart/lines/$lineId", ['expected_version' => 1])->assertForbidden();
        $this->assertDatabaseCount('cart_lines', 1);
    }

    private function allowCustomer(): User
    {
        $user = CustomerProfile::factory()->create(['approval' => 'APPROUVE'])->user;
        $this->actingAs($user);

        return $user;
    }

    private function payload(int $version = 0, int $quantity = 2): array
    {
        return ['quantity' => $quantity, 'units_per_package' => 1, 'expected_version' => $version];
    }

    public function test_pending_or_refused_customer_is_denied_even_with_an_injected_permission(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'EN_ATTENTE']);
        $this->actingAs($profile->user);
        Gate::define('CART_MANAGE_OWN', fn (User $user): bool => true);
        $product = Product::factory()->create(['publication' => 'published']);
        foreach (['EN_ATTENTE', 'REFUSE'] as $state) {
            $profile->approval = $state;
            $profile->save();
            $this->putJson("/api/v1/me/cart/products/{$product->id}", $this->payload())->assertForbidden();
            $this->getJson('/api/v1/me/cart')->assertForbidden();
        }
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_prices_are_recalculated_exactly_and_missing_prices_do_not_become_zero(): void
    {
        $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '0.10']);
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'barcode' => '000012', 'units_per_package' => 12]);
        $url = "/api/v1/me/cart/products/{$product->id}";
        $this->putJson($url, $this->payload(0, 3))->assertOk()->assertJsonPath('data.totals.catalogue_subtotal', '0.30');
        $this->putJson($url, array_replace($this->payload(1, 2), ['units_per_package' => 12]))->assertOk()
            ->assertJsonPath('data.totals.catalogue_subtotal', '2.70');
        $product->sale_price = '0.20';
        $product->save();
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.totals.catalogue_subtotal', '5.40');
        $product->sale_price = null;
        $product->save();
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.totals', null)
            ->assertJsonPath('data.pricing_status', 'price_unavailable')->assertJsonPath('data.lines.0.unit_price', null);
    }

    public function test_success_and_failed_transaction_are_audited_without_raw_request_secrets(): void
    {
        $user = $this->allowCustomer();
        $product = Product::factory()->create(['publication' => 'published']);
        $url = "/api/v1/me/cart/products/{$product->id}";
        $this->putJson($url, $this->payload())->assertOk();
        $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'action' => 'cart.line_updated', 'result' => 'SUCCESS']);
        $this->putJson($url, $this->payload(0, 9) + ['password' => 'must-not-be-logged'])->assertConflict();
        $this->assertDatabaseHas('audit_events', ['action' => 'cart.change', 'result' => 'REFUSED', 'reason' => 'http_409']);
        $this->assertDatabaseHas('cart_lines', ['quantity' => 2]);
        $this->assertStringNotContainsString('must-not-be-logged', AuditEvent::all()->toJson());
    }

    public function test_cart_page_product_entry_and_session_api_use_real_customer_approval(): void
    {
        $user = User::factory()->create(['password' => 'cart-customer-password']);
        CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE', 'customer_code' => 'CLI-CART-TEST']);
        $login = $this->postJson('/api/v1/login', ['identifier' => 'CLI-CART-TEST', 'password' => 'cart-customer-password'])->assertOk();
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        $this->app['auth']->forgetGuards();
        $product = Product::factory()->create(['publication' => 'published']);
        $this->withoutVite();
        $this->get('/panier')->assertOk()->assertSee('customer-cart')->assertSee('Mon panier');
        $this->get('/catalogue/'.$product->id)->assertOk()->assertSee('cart-add');
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/me/cart')->assertOk();
        CustomerProfile::query()->where('user_id', $user->id)->update(['approval' => 'REFUSE']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me/cart')->assertForbidden();
    }
}
