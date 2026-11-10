<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\DeliveryPreparation;
use Database\Seeders\BillingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoicePreviewTest extends TestCase
{
    use RefreshDatabase, \Tests\InvoiceFixtures;

    public function test_same_customer_deliveries_use_historical_prices_and_decimal_totals_without_side_effects(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $first = $this->delivery($actor, $customer, '10.01', 3);
        $second = $this->delivery($actor, $customer, '0.10', 4);
        Product::query()->update(['sale_price' => '999.99']);
        $this->actingAs($actor);
        $listing = $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertCount(2, $listing['data']);
        $body = ['customer_id' => $customer->user_id, 'lines' => [$this->selection($first, 2), $this->selection($second, 3)]];
        $this->postJson('/api/v1/billing/preview', $body)->assertOk()
            ->assertJsonPath('data.total', '20.32')->assertJsonPath('data.currency', 'MAD')
            ->assertJsonPath('data.tax_basis', 'TTC')->assertJsonPath('data.creates_invoice', false);
        $this->postJson('/api/v1/billing/preview', $body)->assertOk()->assertJsonPath('data.total', '20.32');
        $this->assertDatabaseCount('billing_quantity_allocations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseHas('customer_orders', ['id' => $first['order_id'], 'catalogue_subtotal' => '100.00']);
    }

    public function test_billed_quantities_and_unbilled_returns_reduce_available_pieces(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer, '12.50', 10);
        $this->allocation($batch['line_id'], 'invoice', 3);
        $this->allocation($batch['line_id'], 'return_before_invoice', 2);
        $this->actingAs($actor);
        $line = $this->source($batch);
        $this->assertSame(5, $line['remaining_quantity']);
        $this->assertSame('piece', $line['quantity_unit']);
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$this->selection($batch, 5)]])
            ->assertOk()->assertJsonPath('data.total', '62.50');
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$this->selection($batch, 6)]])->assertConflict();
    }

    public function test_preparation_is_not_a_billable_departure(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer);
        $this->actingAs($actor);
        $selection = $this->selection($batch, 1);
        DB::table('delivery_batches')->where('id', $batch['id'])->update(['status' => 'PREPARED', 'dispatched_at' => null]);
        $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$selection]])->assertConflict();
        $this->getJson('/api/v1/billing/deliveries/'.$batch['id'])->assertConflict();
    }

    public function test_mixed_customers_and_unknown_lines_are_rejected(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $first = $this->delivery($actor, $customer);
        $second = $this->delivery($actor, $other);
        $this->actingAs($actor);
        $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$this->selection($first, 1), $this->selection($second, 1)]])->assertUnprocessable();
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [['delivery_line_id' => (string) Str::uuid(), 'quantity' => 1, 'availability_token' => str_repeat('a', 64)]]])->assertUnprocessable();
    }

    public function test_preview_rejects_stale_availability_and_order_price_changes(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer);
        $this->actingAs($actor);
        $selection = $this->selection($batch, 1);
        $this->allocation($batch['line_id'], 'invoice', 1);
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$selection]])->assertConflict();
        $selection = $this->selection($batch, 1);
        DB::table('customer_orders')->where('id', $batch['order_id'])->increment('version');
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$selection]])->assertConflict();
    }

    public function test_real_roles_revocation_and_customer_exclusion_protect_every_endpoint(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer);
        $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertUnauthorized();
        $this->actingAs($actor)->get('/gestion/facturation')->assertOk()->assertSee('Préparer une facture');
        $body = ['customer_id' => $customer->user_id, 'lines' => [$this->selection($batch, 1)]];
        $actor->roles()->detach();
        $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertForbidden();
        $this->getJson('/api/v1/billing/deliveries/'.$batch['id'])->assertForbidden();
        $this->postJson('/api/v1/billing/preview', $body)->assertForbidden();
        $this->get('/gestion/facturation')->assertForbidden();
        $this->grant($customer->user);
        $this->actingAs($customer->user)->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertForbidden();
        $this->getJson('/api/v1/billing/deliveries/'.$batch['id'])->assertForbidden();
        $this->postJson('/api/v1/billing/preview', $body)->assertForbidden();
    }

    public function test_missing_or_non_mad_ttc_prices_fail_closed_and_secrets_are_never_projected(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer);
        $order = DB::table('customer_orders')->find($batch['order_id']);
        $lines = json_decode($order->lines, true);
        $lines[0]['pricing']['currency'] = 'EUR';
        $lines[0]['internal_notes'] = 'PRIVATE-MARKER';
        DB::table('customer_orders')->where('id', $order->id)->update(['lines' => json_encode($lines)]);
        $this->actingAs($actor);
        $line = $this->source($batch);
        $this->assertNull($line['unit_price']);
        $this->assertSame('historical_price_unavailable', $line['pricing_status']);
        $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertOk()->assertDontSee('PRIVATE-MARKER')->assertDontSee('purchase_price')->assertDontSee('EUR');
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$this->selection($batch, 1)]])->assertConflict();
    }

    public function test_invalid_quantities_duplicates_and_browser_prices_are_rejected(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer);
        $this->actingAs($actor);
        $line = $this->selection($batch, 1);
        foreach ([0, -1, 1.5] as $invalid) {
            $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [array_replace($line, ['quantity' => $invalid])]])->assertUnprocessable();
        }
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$line, array_replace($line, ['delivery_line_id' => strtoupper($line['delivery_line_id'])])]])->assertUnprocessable();
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$line + ['unit_price' => '0.01']]])->assertUnprocessable();
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$line], 'total' => '0.01'])->assertUnprocessable();
    }

    public function test_actual_partial_dispatch_is_the_source_and_preview_does_not_move_stock_again(): void
    {
        $actor = $this->employee(['DELIVERY_PREPARE', 'DELIVERY_DEPARTURE_CONFIRM']);
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $fixture = $this->delivery($actor, $customer, '4.25', 5);
        DB::table('delivery_batch_lines')->where('batch_id', $fixture['id'])->delete();
        DB::table('delivery_batches')->where('id', $fixture['id'])->delete();
        $warehouse = Warehouse::findOrFail($fixture['warehouse_id']);
        $balance = StockBalance::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $fixture['product_id'], 'physical' => 5, 'reserved' => 5]);
        $operation = (string) Str::uuid();
        DB::table('stock_operations')->insert(['id' => $operation, 'request_key' => (string) Str::uuid(), 'actor_id' => $actor->id, 'action' => 'reserve', 'request_hash' => str_repeat('b', 64), 'result' => '{}', 'created_at' => now('UTC')]);
        DB::table('stock_reservations')->insert(['id' => (string) Str::uuid(), 'order_id' => $fixture['order_id'], 'line_id' => $fixture['order_line_id'], 'balance_id' => $balance->id, 'quantity' => 5, 'order_version' => 1, 'operation_id' => $operation, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_orders')->where('id', $fixture['order_id'])->update(['status' => 'CONFIRMEE']);
        $deliveries = app(DeliveryPreparation::class);
        $batch = $deliveries->claim($actor, ['order_id' => $fixture['order_id'], 'warehouse_id' => $warehouse->id]);
        $deliveries->scan($actor, $batch['id'], ['code' => $fixture['sku'], 'mode' => 'reference'], (string) Str::uuid());
        $deliveries->validatePreparation($actor, $batch['id'], ['confirm_partial' => true]);
        $deliveries->dispatch($actor, $batch['id']);
        $this->actingAs($actor);
        $line = $this->getJson('/api/v1/billing/deliveries/'.$batch['id'])->assertOk()->json('data.lines.0');
        $this->assertSame(1, $line['remaining_quantity']);
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [['delivery_line_id' => $line['id'], 'quantity' => 1, 'availability_token' => $line['availability_token']]]])->assertOk()->assertJsonPath('data.total', '4.25');
        $this->assertDatabaseHas('stock_balances', ['id' => $balance->id, 'physical' => 4, 'reserved' => 4]);
        $this->assertSame(1, DB::table('stock_movements')->where('kind', 'dispatch')->count());
    }

    public function test_permission_seeder_is_repeatable_and_assigns_no_roles(): void
    {
        $this->seed(BillingPermissionSeeder::class);
        $this->seed(BillingPermissionSeeder::class);
        $this->assertSame(1, Permission::where('code', 'INVOICE_PREVIEW')->count());
        $this->assertDatabaseCount('role_permissions', 0);
    }

    public function test_missing_departure_author_and_overallocated_lines_are_not_billable(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer);
        $this->actingAs($actor);
        $selection = $this->selection($batch, 1);
        DB::table('delivery_batches')->where('id', $batch['id'])->update(['dispatched_by' => null]);
        $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$selection]])->assertConflict();
        DB::table('delivery_batches')->where('id', $batch['id'])->update(['dispatched_by' => $actor->id]);
        $this->allocation($batch['line_id'], 'invoice', 6);
        $this->getJson('/api/v1/billing/deliveries?customer_id='.$customer->user_id)->assertConflict();
    }

    public function test_selection_accepts_canonical_uuid_case_and_piece_quantity_for_packaged_lines(): void
    {
        $actor = $this->employee();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer, '1.25', 10);
        DB::table('delivery_batch_lines')->where('id', $batch['line_id'])->update(['units_per_package' => 10]);
        $this->actingAs($actor);
        $selection = $this->selection($batch, 2);
        $selection['delivery_line_id'] = strtoupper($selection['delivery_line_id']);
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$selection]])->assertOk()
            ->assertJsonPath('data.total', '2.50')->assertJsonPath('data.lines.0.units_per_package', 10);
    }
}
