<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['DELIVERY_PREPARE', 'DELIVERY_TRANSFER', 'DELIVERY_DEPARTURE_CONFIRM'] as $permission) {
            Gate::define($permission, fn (): bool => true);
        }
    }

    public function test_scan_partial_preparation_and_departure_are_separate_idempotent_operations(): void
    {
        [$actor, $orderId, $warehouse, $product, $balance] = $this->reservedOrder(5);
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'barcode' => '0012345000', 'units_per_package' => 2]);
        $this->actingAs($actor);

        $ready = $this->getJson('/api/v1/deliveries/ready')->assertOk()->json('data.0');
        $batch = $this->postJson('/api/v1/deliveries/claim', ['order_id' => $orderId, 'warehouse_id' => $warehouse->id])->assertOk()->json('data');
        $this->assertSame($orderId, $ready['order_id']);
        $this->assertSame(5, $batch['lines'][0]['expected']);

        $wrong = Product::factory()->create();
        DB::table('product_barcodes')->insert(['product_id' => $wrong->id, 'barcode' => 'OUTSIDE', 'units_per_package' => 1]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => 'OUTSIDE', 'mode' => 'scanner'])->assertStatus(422)->assertJsonFragment(['message' => 'Ce produit ne figure pas dans ce BL']);

        $key = (string) Str::uuid();
        $scan = $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => '0012345000', 'mode' => 'scanner'])->assertOk()->json('data');
        $replay = $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => '0012345000', 'mode' => 'scanner'])->assertOk()->json('data');
        $this->assertSame(2, $scan['batch']['lines'][0]['prepared']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(2, DB::table('delivery_batch_lines')->where('batch_id', $batch['id'])->value('prepared_quantity'));
        $this->assertDatabaseHas('stock_balances', ['id' => $balance->id, 'physical' => 5, 'reserved' => 5]);
        DB::table('product_barcodes')->insert(['product_id' => $product->id, 'barcode' => 'TOO-LARGE', 'units_per_package' => 4]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => 'TOO-LARGE', 'mode' => 'barcode'])->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => '000-UNKNOWN', 'mode' => 'barcode'])->assertStatus(422);
        $this->assertSame(2, DB::table('delivery_batch_lines')->where('batch_id', $batch['id'])->value('prepared_quantity'));

        $this->postJson("/api/v1/deliveries/batches/{$batch['id']}/prepare", ['confirm_partial' => false])->assertStatus(409);
        $prepared = $this->postJson("/api/v1/deliveries/batches/{$batch['id']}/prepare", ['confirm_partial' => true])->assertOk()->json('data');
        $this->assertSame('PREPARED', $prepared['status']);
        $this->assertDatabaseHas('stock_balances', ['id' => $balance->id, 'physical' => 5, 'reserved' => 5]);

        $dispatched = $this->postJson("/api/v1/deliveries/batches/{$batch['id']}/dispatch")->assertOk()->json('data');
        $this->assertSame('DISPATCHED', $dispatched['status']);
        $this->assertDatabaseHas('stock_balances', ['id' => $balance->id, 'physical' => 3, 'reserved' => 3]);
        $this->assertDatabaseHas('customer_orders', ['id' => $orderId, 'status' => 'PARTIELLEMENT_EXPEDIEE']);
        $this->assertDatabaseCount('delivery_scan_events', 1);
        $this->assertDatabaseHas('stock_movements', ['balance_id' => $balance->id, 'kind' => 'dispatch', 'physical_delta' => -2, 'reserved_delta' => -2]);
        $this->postJson("/api/v1/deliveries/batches/{$batch['id']}/dispatch")->assertOk();
        $this->assertSame(1, DB::table('stock_movements')->where('kind', 'dispatch')->count());
        $this->get($dispatched['document_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertSee('%PDF-1.4', false);
    }

    public function test_reference_entry_preserves_server_controls_and_customers_are_refused(): void
    {
        [$actor, $orderId, $warehouse, $product] = $this->reservedOrder(1);
        $this->actingAs($actor);
        $batch = $this->postJson('/api/v1/deliveries/claim', ['order_id' => $orderId, 'warehouse_id' => $warehouse->id])->assertOk()->json('data');
        $reference = ' '.strtolower($product->original_reference).' ';
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => $reference, 'mode' => 'reference'])->assertOk()->assertJsonPath('data.batch.lines.0.prepared', 1);

        $customer = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $customer->id, 'approval' => 'APPROUVE']);
        $this->actingAs($customer)->getJson('/api/v1/deliveries/ready')->assertForbidden();
    }

    public function test_authorized_transfer_changes_the_only_preparer_and_is_audited(): void
    {
        [$actor, $orderId, $warehouse, $product] = $this->reservedOrder(1);
        $target = User::factory()->create();
        $this->actingAs($actor);
        $batch = $this->postJson('/api/v1/deliveries/claim', ['order_id' => $orderId, 'warehouse_id' => $warehouse->id])->assertOk()->json('data');
        $transferred = $this->postJson("/api/v1/deliveries/batches/{$batch['id']}/transfer", ['target_user_id' => $target->id])->assertOk()->json('data');
        $this->assertSame($target->id, $transferred['claimed_by']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => $product->sku, 'mode' => 'reference'])->assertForbidden();
        $this->actingAs($target)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/deliveries/batches/{$batch['id']}/scan", ['code' => $product->sku, 'mode' => 'reference'])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'delivery.transferred', 'object_id' => $batch['id']]);
    }

    /** @return array{User, int, Warehouse, Product, StockBalance} */
    private function reservedOrder(int $quantity): array
    {
        $actor = User::factory()->create();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $balance = StockBalance::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'physical' => $quantity, 'reserved' => $quantity]);
        $lineId = (string) Str::uuid();
        $orderId = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(), 'status' => 'CONFIRMEE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}', 'lines' => json_encode([['id' => $lineId, 'product_id' => $product->id, 'quantity' => $quantity, 'units_per_package' => 1]], JSON_THROW_ON_ERROR), 'catalogue_subtotal' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        $operation = (string) Str::uuid();
        DB::table('stock_operations')->insert(['id' => $operation, 'request_key' => (string) Str::uuid(), 'actor_id' => $actor->id, 'action' => 'reserve', 'request_hash' => str_repeat('b', 64), 'result' => '{}', 'created_at' => now('UTC')]);
        DB::table('stock_reservations')->insert(['id' => (string) Str::uuid(), 'order_id' => $orderId, 'line_id' => $lineId, 'balance_id' => $balance->id, 'quantity' => $quantity, 'order_version' => 1, 'operation_id' => $operation, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return [$actor, $orderId, $warehouse, $product, $balance];
    }
}
