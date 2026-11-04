<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CustomerOrderDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
    }

    public function test_demo_fixtures_are_repeatable_and_leave_a_real_order_and_cart(): void
    {
        $this->seed(CustomerOrderDemoSeeder::class);
        $this->seed(CustomerOrderDemoSeeder::class);
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('customer_profiles', ['customer_code' => 'CLI-FTP-DEMO', 'approval' => 'APPROUVE']);
        $this->assertStringNotContainsString('ftp-local-demo-only', AuditEvent::all()->toJson());
    }

    public function test_demo_fixtures_refuse_production_without_creating_accounts(): void
    {
        $this->app->instance('env', 'production');
        try {
            app(CustomerOrderDemoSeeder::class)->run();
            $this->fail('The demo seeder must refuse production.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('users', 0);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE']);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function checkout(?Product $product = null): array
    {
        $product ??= Product::factory()->create(['publication' => 'published', 'sale_price' => '12.50']);
        $cart = $this->getJson('/api/v1/me/cart')->assertOk()->json('data');
        $cart = $this->putJson('/api/v1/me/cart/products/'.$product->id,
            ['expected_version' => $cart['version'], 'quantity' => 2, 'units_per_package' => 1])->assertOk()->json('data');

        return [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => $cart['version'],
            'pricing_token' => $cart['pricing_token'],
            'contact' => ['name' => 'Client Test', 'email' => 'client@example.test', 'phone' => '0612345678'],
            'delivery_address' => ['address' => 'Rue Livraison', 'city' => 'Casablanca', 'country' => 'Maroc', 'postal_code' => '20000'],
            'billing_address' => ['address' => 'Rue Facturation', 'city' => 'Rabat', 'country' => 'Maroc'],
            'customer_note' => 'Appeler avant livraison.',
        ];
    }

    public function test_submission_records_snapshots_and_clears_cart_only_on_success(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '12.50', 'net_purchase_price' => '8.00']);
        $payload = $this->checkout($product);
        $before = $product->fresh()->getAttributes();
        $order = $this->postJson('/api/v1/me/orders', $payload)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')->assertJsonPath('data.status', 'SOUMISE')
            ->assertJsonPath('data.totals.catalogue_subtotal', '25.00')
            ->assertJsonPath('data.commercial_terms.fees', null)
            ->assertJsonPath('data.commercial_terms.status', 'pending_human_review')
            ->assertJsonPath('data.delivery_address.address', 'Rue Livraison')
            ->assertJsonPath('data.billing_address.city', 'Rabat')->json('data');
        $this->assertMatchesRegularExpression('/^CMD-[0-9A-Z]{26}$/', $order['number']);
        $this->assertStringContainsString('validation humaine', $order['confirmation']);
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('cart_lines', 0);
        $this->getJson('/api/v1/me/cart')->assertJsonPath('data.version', 2);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'action' => 'order.submitted', 'result' => 'SUCCESS']);
        $row = DB::table('customer_orders')->first();
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame(CustomerProfile::where('user_id', $user->id)->value('customer_code'), json_decode($row->customer_snapshot, true)['customer_code']);
        $this->assertArrayNotHasKey('request_hash', $order);
        $this->assertArrayNotHasKey('net_purchase_price', $order['lines'][0]);
        $this->assertTrue(Str::isUuid($order['lines'][0]['id']));
        $this->assertSame($product->original_reference, $order['lines'][0]['original_reference']);
    }

    public function test_replay_returns_same_order_after_cart_changes_and_payload_key_order_does_not_matter(): void
    {
        $this->customer();
        $payload = $this->checkout();
        $order = $this->postJson('/api/v1/me/orders', $payload)->assertCreated()->json('data');
        $this->checkout();
        $reordered = $payload;
        $reordered['contact'] = array_reverse($payload['contact'], true);
        $this->postJson('/api/v1/me/orders', $reordered)->assertOk()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $order['id']);
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'order.replayed']);
        $payload['customer_note'] = 'Autre demande';
        $this->postJson('/api/v1/me/orders', $payload)->assertConflict();
        $this->assertDatabaseCount('customer_orders', 1);
    }

    public function test_changed_price_requires_review_and_failed_submission_preserves_cart(): void
    {
        $this->customer();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '10.00']);
        $payload = $this->checkout($product);
        $product->forceFill(['sale_price' => '11.00'])->save();
        $this->postJson('/api/v1/me/orders', $payload)->assertConflict();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
        $cart = $this->getJson('/api/v1/me/cart')->assertOk()->json('data');
        $payload['pricing_token'] = $cart['pricing_token'];
        $this->postJson('/api/v1/me/orders', $payload)->assertCreated()->assertJsonPath('data.totals.catalogue_subtotal', '22.00');
    }

    public function test_stale_cart_empty_cart_archived_and_unpriced_products_are_rejected(): void
    {
        $this->customer();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '10.00']);
        $payload = $this->checkout($product);
        $stale = $payload;
        $stale['expected_version'] = 0;
        $this->postJson('/api/v1/me/orders', $stale)->assertConflict();
        $product->forceFill(['publication' => 'archived'])->save();
        $this->postJson('/api/v1/me/orders', $payload)->assertUnprocessable();
        $product->forceFill(['publication' => 'published', 'sale_price' => null])->save();
        $this->postJson('/api/v1/me/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
        $product->forceFill(['sale_price' => '10.00'])->save();
        $this->postJson('/api/v1/me/orders', $payload)->assertCreated();
        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['expected_version'] = 2;
        $this->postJson('/api/v1/me/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('customer_orders', 1);
    }

    public function test_snapshots_remain_unchanged_when_product_and_profile_change(): void
    {
        $user = $this->customer();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '0.10', 'designation' => 'Article original']);
        $payload = $this->checkout($product);
        $order = $this->postJson('/api/v1/me/orders', $payload)->assertCreated()->json('data');
        $reference = $product->original_reference;
        $product->forceFill(['sale_price' => '99.00', 'designation' => 'Renommé', 'original_reference' => 'NEW-REF', 'publication' => 'archived'])->save();
        CustomerProfile::where('user_id', $user->id)->update(['address' => 'Nouvelle adresse']);
        $this->getJson('/api/v1/me/orders/'.$order['id'])->assertOk()
            ->assertJsonPath('data.lines.0.designation', 'Article original')
            ->assertJsonPath('data.lines.0.original_reference', $reference)
            ->assertJsonPath('data.lines.0.unit_price', '0.10')
            ->assertJsonPath('data.totals.catalogue_subtotal', '0.20')
            ->assertJsonPath('data.delivery_address.address', 'Rue Livraison');
    }

    public function test_owned_history_and_detail_do_not_expose_other_customers_orders(): void
    {
        $owner = $this->customer();
        $payload = $this->checkout();
        $order = $this->postJson('/api/v1/me/orders', $payload)->assertCreated()->json('data');
        $this->getJson('/api/v1/me/orders')->assertOk()->assertJsonCount(1, 'data');
        $this->customer();
        $this->getJson('/api/v1/me/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/me/orders/'.$order['id'])->assertNotFound();
        $this->getJson('/api/v1/me/orders/999999')->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/me/orders/'.$order['id'])->assertOk();
    }

    public function test_guests_staff_and_revoked_customers_cannot_submit_or_replay(): void
    {
        $this->postJson('/api/v1/me/orders', [])->assertUnauthorized();
        $this->getJson('/api/v1/me/orders')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/me/orders', [])->assertForbidden();
        $this->getJson('/api/v1/me/orders')->assertForbidden();
        $user = $this->customer();
        $payload = $this->checkout();
        $this->postJson('/api/v1/me/orders', $payload)->assertCreated();
        CustomerProfile::where('user_id', $user->id)->update(['approval' => 'REFUSE']);
        $this->postJson('/api/v1/me/orders', $payload)->assertForbidden();
        $this->getJson('/api/v1/me/orders')->assertForbidden();
        Gate::define('ORDER_SUBMIT_OWN', fn (User $user): bool => true);
        $this->postJson('/api/v1/me/orders', $payload)->assertForbidden();
    }

    public function test_invalid_contact_addresses_and_forged_fields_preserve_cart_without_logging_sensitive_input(): void
    {
        $this->customer();
        $payload = $this->checkout();
        foreach (['user_id', 'status', 'number', 'lines', 'total', 'net_purchase_price'] as $field) {
            $this->postJson('/api/v1/me/orders', $payload + [$field => 'forged'])->assertUnprocessable();
        }
        $invalid = $payload;
        $invalid['contact']['email'] = 'not-an-email';
        $invalid['delivery_address']['address'] = '';
        $this->postJson('/api/v1/me/orders', $invalid + ['password' => 'never-log-this'])->assertUnprocessable();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertStringNotContainsString('never-log-this', AuditEvent::all()->toJson());
        $this->assertStringNotContainsString('Rue Livraison', AuditEvent::all()->toJson());
        $this->assertDatabaseHas('audit_events', ['action' => 'order.submit', 'result' => 'REFUSED']);
    }

    public function test_audit_failure_rolls_back_order_and_preserves_cart(): void
    {
        $this->customer();
        $payload = $this->checkout();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'order.submitted') {
                throw new RuntimeException('Simulated audit storage failure');
            }
        });
        try {
            $this->postJson('/api/v1/me/orders', $payload)->assertServerError();
            $this->assertDatabaseCount('customer_orders', 0);
            $this->assertDatabaseCount('cart_lines', 1);
            $this->getJson('/api/v1/me/cart')->assertJsonPath('data.version', 1);
        } finally {
            AuditEvent::flushEventListeners();
        }
    }

    public function test_packaging_quantities_are_snapshotted_and_deleted_packaging_is_rejected(): void
    {
        $this->customer();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '0.10']);
        $payload = $this->checkout($product);
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'barcode' => '1234567890123', 'units_per_package' => 12]);
        $this->putJson('/api/v1/me/cart/products/'.$product->id, ['quantity' => 3, 'units_per_package' => 12, 'expected_version' => 1])->assertOk();
        $cart = $this->getJson('/api/v1/me/cart')->json('data');
        $payload['expected_version'] = $cart['version'];
        $payload['pricing_token'] = $cart['pricing_token'];
        DB::table('product_barcodes')->where('product_id', $product->id)->delete();
        $this->postJson('/api/v1/me/orders', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('cart_lines', 2);
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'barcode' => '1234567890123', 'units_per_package' => 12]);
        $this->postJson('/api/v1/me/orders', $payload)->assertCreated()->assertJsonPath('data.totals.catalogue_subtotal', '3.80')
            ->assertJsonPath('data.lines.1.units_per_package', 12)->assertJsonPath('data.lines.1.quantity', 3);
    }

    public function test_history_is_paginated_and_authenticated_page_mounts_real_react_flow(): void
    {
        $user = $this->customer();
        $payload = $this->checkout();
        $this->postJson('/api/v1/me/orders', $payload)->assertCreated();
        $original = (array) DB::table('customer_orders')->first();
        unset($original['id']);
        for ($index = 0; $index < 20; $index++) {
            DB::table('customer_orders')->insert(array_replace($original, ['number' => 'CMD-PAGE-'.$index, 'idempotency_key' => (string) Str::uuid()]));
        }
        $this->getJson('/api/v1/me/orders')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/me/orders?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->withoutVite();
        $this->get('/mes-commandes')->assertOk()->assertSee('customer-orders');
        $this->get('/panier')->assertOk()->assertSee('data-orders', false);
    }
}
