<?php

namespace Tests\Feature;

use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesLineProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
        DB::table('pricing_settings')->where('id', 1)->update(['currency' => 'MAD', 'tax_basis' => 'TTC']);
    }

    /** @return array{int, Product, CustomerProfile} */
    private function scenario(): array
    {
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        CustomerDiscount::factory()->for($customer)->create(['percentage' => '30.00']);
        Promotion::factory()->for($product)->enabled()->create(['kind' => 'fixed_price', 'value' => '80.00']);
        $line = ['id' => (string) Str::uuid(), 'product_id' => (string) $product->id, 'quantity' => 1,
            'units_per_package' => 1, 'designation' => 'Original', 'unit_price' => '100.00', 'line_subtotal' => '100.00'];
        $orderId = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(),
            'status' => 'SOUMISE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}',
            'lines' => json_encode([$line]), 'catalogue_subtotal' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach (['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE'] as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]);
            $role->permissions()->attach($permission);
        }
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        $this->actingAs($actor)->postJson("/api/v1/sales/orders/$orderId/claim", ['expected_version' => 1])->assertOk();

        return [$orderId, $product, $customer];
    }

    /** @return list<array{product_id: int, quantity: int, units_per_package: int}> */
    private function lines(Product $product): array
    {
        return [['product_id' => $product->id, 'quantity' => 2, 'units_per_package' => 1]];
    }

    /** @return array<string, mixed> */
    private function proposal(int $id, Product $product): array
    {
        $lines = $this->lines($product);
        $preview = $this->postJson("/api/v1/sales/orders/$id/line-preview", ['expected_version' => 2, 'lines' => $lines])->assertOk()->json('data');

        return ['kind' => 'lines', 'expected_version' => 2, 'request_key' => (string) Str::uuid(),
            'changes' => ['lines' => $lines], 'pricing_token' => $preview['pricing_token'], 'reason' => 'Quantité demandée à vérifier'];
    }

    public function test_real_customer_best_price_is_frozen_in_a_pending_proposal_without_overwriting_originals(): void
    {
        [$id, $product] = $this->scenario();
        $original = DB::table('customer_orders')->where('id', $id)->first();
        $payload = $this->proposal($id, $product);
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertOk()->assertJsonPath('data.requires_agreement', true);
        $revision = $this->getJson("/api/v1/sales/orders/$id")->assertOk()->json('data.revisions.0.after');
        $this->assertSame('70.00', $revision['lines'][0]['unit_price']);
        $this->assertSame('customer_discount', $revision['lines'][0]['pricing']['applied_source']);
        $this->assertSame('140.00', $revision['estimated_subtotal']);
        $this->assertSame('MAD', $revision['currency']);
        $this->assertSame('TTC', $revision['tax_basis']);
        $this->assertSame(json_decode($original->lines, true)[0]['id'], $revision['lines'][0]['id']);
        $after = DB::table('customer_orders')->where('id', $id)->first();
        $this->assertSame($original->lines, $after->lines);
        $this->assertSame($original->catalogue_subtotal, $after->catalogue_subtotal);
        $this->assertSame('EN_VERIFICATION', $after->status);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_price_change_requires_another_preview_and_failure_preserves_order_version(): void
    {
        [$id, $product] = $this->scenario();
        $payload = $this->proposal($id, $product);
        $product->forceFill(['sale_price' => '200.00'])->save();
        Promotion::where('product_id', $product->id)->update(['state' => 'disabled']);
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertConflict();
        $this->assertDatabaseCount('sales_order_revisions', 0);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 2]);
        $fresh = $this->proposal($id, $product);
        $this->postJson("/api/v1/sales/orders/$id/revisions", $fresh)->assertOk();
        $this->getJson("/api/v1/sales/orders/$id")->assertOk()->assertJsonPath('data.revisions.0.after.lines.0.unit_price', '140.00');
    }

    public function test_replay_returns_original_proposal_even_after_catalogue_changes(): void
    {
        [$id, $product] = $this->scenario();
        $payload = $this->proposal($id, $product);
        $revision = $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertOk()->json('data.revision_id');
        $product->forceFill(['publication' => 'archived'])->save();
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertOk()->assertJsonPath('data.revision_id', $revision)->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/sales/orders/$id/revisions", array_replace($payload, ['reason' => 'Changed']))->assertConflict();
        $this->assertDatabaseCount('sales_order_revisions', 1);
    }

    public function test_invalid_lines_prices_packagings_and_stale_preview_are_rejected(): void
    {
        [$id, $product] = $this->scenario();
        $lines = $this->lines($product);
        $url = "/api/v1/sales/orders/$id/line-preview";
        $this->postJson($url, ['expected_version' => 1, 'lines' => $lines])->assertConflict();
        $this->postJson($url, ['expected_version' => 2, 'lines' => [$lines[0] + ['unit_price' => '0.01']]])->assertUnprocessable();
        $this->postJson($url, ['expected_version' => 2, 'lines' => array_merge($lines, $lines)])->assertUnprocessable();
        $this->postJson($url, ['expected_version' => 2, 'lines' => [array_replace($lines[0], ['quantity' => 0])]])->assertUnprocessable();
        $this->postJson($url, ['expected_version' => 2, 'lines' => [array_replace($lines[0], ['units_per_package' => 12])]])->assertUnprocessable();
        $this->assertDatabaseCount('sales_order_revisions', 0);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 2]);
    }

    public function test_preview_does_not_bypass_mad_ttc_or_customer_approval(): void
    {
        [$id, $product, $customer] = $this->scenario();
        DB::table('pricing_settings')->where('id', 1)->update(['tax_basis' => null]);
        $this->postJson("/api/v1/sales/orders/$id/line-preview", ['expected_version' => 2, 'lines' => $this->lines($product)])->assertUnprocessable();
        DB::table('pricing_settings')->where('id', 1)->update(['tax_basis' => 'TTC']);
        $customer->forceFill(['approval' => 'REFUSE'])->save();
        $this->postJson("/api/v1/sales/orders/$id/line-preview", ['expected_version' => 2, 'lines' => $this->lines($product)])->assertForbidden();
    }

    public function test_substitution_stays_a_proposal_with_a_distinct_line_identity(): void
    {
        [$id] = $this->scenario();
        $original = DB::table('customer_orders')->where('id', $id)->value('lines');
        $replacement = Product::factory()->create(['publication' => 'published', 'sale_price' => '25.00']);
        $payload = $this->proposal($id, $replacement);
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertOk()->assertJsonPath('data.requires_agreement', true);
        $line = $this->getJson("/api/v1/sales/orders/$id")->assertOk()->json('data.revisions.0.after.lines.0');
        $this->assertSame((string) $replacement->id, $line['product_id']);
        $this->assertTrue(Str::isUuid($line['id']));
        $this->assertNotSame(json_decode($original, true)[0]['id'], $line['id']);
        $this->assertSame($original, DB::table('customer_orders')->where('id', $id)->value('lines'));
    }
}
