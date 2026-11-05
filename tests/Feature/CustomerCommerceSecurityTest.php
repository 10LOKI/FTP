<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCommerceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
    }

    /** @return array<string, mixed> */
    private function submission(): array
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '10.00']);
        $cart = $this->putJson('/api/v1/me/cart/products/'.$product->id,
            ['quantity' => 1, 'units_per_package' => 1, 'expected_version' => 0])->assertOk()->json('data');
        $address = ['address' => 'Adresse fictive', 'city' => 'Rabat', 'country' => 'Maroc'];

        return ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $cart['version'],
            'pricing_token' => $cart['pricing_token'], 'contact' => ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '0000000000'],
            'delivery_address' => $address, 'billing_address' => $address];
    }

    public function test_uuid_case_does_not_change_the_identity_of_a_submission(): void
    {
        $payload = $this->submission();
        $id = $this->postJson('/api/v1/me/orders', $payload)->assertCreated()->json('data.id');
        $payload['idempotency_key'] = strtoupper($payload['idempotency_key']);
        $this->postJson('/api/v1/me/orders', $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('customer_orders', 1);
    }

    public function test_cart_stays_removable_when_pricing_is_unavailable(): void
    {
        $payload = $this->submission();
        $line = DB::table('cart_lines')->first();
        config(['pricing.approved' => false]);
        $this->getJson('/api/v1/me/cart')->assertOk()->assertJsonPath('data.checkout_ready', false)
            ->assertJsonPath('data.lines.0.unit_price', null);
        $this->postJson('/api/v1/me/orders', $payload)->assertUnprocessable();
        $this->deleteJson('/api/v1/me/cart/lines/'.$line->id, ['expected_version' => 1])->assertOk()
            ->assertJsonCount(0, 'data.lines');
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_history_projects_only_authorized_snapshot_fields(): void
    {
        $id = $this->postJson('/api/v1/me/orders', $this->submission())->assertCreated()->json('data.id');
        $row = DB::table('customer_orders')->where('id', $id)->first();
        $lines = json_decode($row->lines, true);
        $lines[0]['net_purchase_price'] = 'PRIVATE-COST';
        $lines[0]['pricing']['internal_notes'] = 'PRIVATE-NOTE';
        $contact = json_decode($row->contact, true) + ['internal_notes' => 'PRIVATE-CONTACT'];
        $terms = json_decode($row->commercial_snapshot, true) + ['internal_margin' => 'PRIVATE-MARGIN'];
        $terms['discounts'][0]['pricing']['internal_notes'] = 'PRIVATE-TERMS';
        DB::table('customer_orders')->where('id', $id)->update([
            'lines' => json_encode($lines), 'contact' => json_encode($contact), 'commercial_snapshot' => json_encode($terms),
        ]);
        foreach (['/api/v1/me/orders/'.$id, '/api/v1/me/orders'] as $url) {
            $response = $this->getJson($url)->assertOk();
            $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        }
    }

    public function test_stateful_writes_require_csrf_outside_the_testing_bypass(): void
    {
        $this->submission();
        config(['sanctum.stateful' => ['localhost:8090']]);
        $this->app->instance('env', 'local');
        try {
            $this->withHeader('Origin', 'http://localhost:8090')
                ->deleteJson('/api/v1/me/cart/lines/'.DB::table('cart_lines')->value('id'), ['expected_version' => 1])
                ->assertStatus(419);
            $this->assertDatabaseCount('cart_lines', 1);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_write_rate_limit_does_not_exhaust_the_read_budget_or_create_orders(): void
    {
        $this->submission();
        for ($attempt = 0; $attempt < 29; $attempt++) {
            $this->postJson('/api/v1/me/orders', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/me/orders', [])->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/v1/me/cart')->assertOk();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
    }
}
