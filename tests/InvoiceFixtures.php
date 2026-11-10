<?php

namespace Tests;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryPreparation;
use App\Services\InvoiceIssuanceContext;
use App\Services\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait InvoiceFixtures
{
    protected function employee(array $extra = []): User
    {
        $actor = User::factory()->create();
        $this->grant($actor, $extra);

        return $actor;
    }

    protected function grant(User $actor, array $extra = []): void
    {
        $role = Role::factory()->create();
        foreach (array_merge(['INVOICE_PREVIEW'], $extra) as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'billing', 'label' => $code]);
            $role->permissions()->attach($permission->id);
        }
        $actor->roles()->attach($role->id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
    }

    protected function delivery(User $actor, CustomerProfile $customer, string $price = '5.00', int $quantity = 5): array
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $orderLineId = (string) Str::uuid();
        $orderId = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(), 'status' => 'EXPEDIEE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}', 'lines' => json_encode([['id' => $orderLineId, 'product_id' => $product->id, 'quantity' => $quantity, 'units_per_package' => 1, 'unit_price' => $price, 'pricing' => ['currency' => 'MAD', 'tax_basis' => 'TTC'], 'internal_notes' => 'PRIVATE-MARKER']]), 'catalogue_subtotal' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        $batchId = (string) Str::uuid();
        DB::table('delivery_batches')->insert(['id' => $batchId, 'number' => 'BL-'.Str::ulid(), 'order_id' => $orderId, 'warehouse_id' => $warehouse->id, 'claimed_by' => $actor->id, 'claimed_at' => now(), 'prepared_by' => $actor->id, 'prepared_at' => now(), 'dispatched_by' => $actor->id, 'dispatched_at' => now(), 'status' => 'DISPATCHED', 'stock_request_key' => (string) Str::uuid(), 'document_sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $lineId = (string) Str::uuid();
        DB::table('delivery_batch_lines')->insert(['id' => $lineId, 'batch_id' => $batchId, 'order_line_id' => $orderLineId, 'product_id' => $product->id, 'expected_quantity' => $quantity, 'prepared_quantity' => $quantity, 'sku' => $product->sku, 'designation' => $product->designation, 'original_reference' => $product->original_reference, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $batchId, 'line_id' => $lineId, 'order_id' => $orderId, 'order_line_id' => $orderLineId, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'sku' => $product->sku];
    }

    protected function source(array $batch): array
    {
        return $this->getJson('/api/v1/billing/deliveries/'.$batch['id'])->assertOk()->json('data.lines.0');
    }

    protected function selection(array $batch, int $quantity): array
    {
        $line = $this->source($batch);

        return ['delivery_line_id' => $line['id'], 'quantity' => $quantity, 'availability_token' => $line['availability_token']];
    }

    protected function allocation(string $lineId, string $kind, int $quantity): void
    {
        DB::table('billing_quantity_allocations')->insert(['id' => (string) Str::uuid(), 'delivery_line_id' => $lineId, 'kind' => $kind, 'quantity' => $quantity, 'source_id' => (string) Str::uuid(), 'created_at' => now()]);
    }

    protected function enableTestBilling(): void
    {
        config(['billing.issuance_enabled' => true, 'billing.policy_version' => 'integration-test-v1',
            'billing.number_prefix' => 'TEST-INTEGRATION-', 'billing.sequence_key' => 'integration-test',
            'billing.seller' => ['name' => 'Vendeur de test', 'address' => 'Adresse de test'],
            'billing.tax_presentation' => 'ttc_only', 'billing.tax_note' => 'Conditions TTC de test']);
    }

    protected function confirmBilling(array $order, CustomerProfile $customer, string $terms = 'Conditions de test'): void
    {
        $row = DB::table('customer_orders')->find($order['order_id']);
        DB::table('customer_orders')->where('id', $row->id)->update(['commercial_snapshot' => json_encode(['billing_v1' => [
            'status' => 'confirmed', 'confirmed_by' => 1, 'confirmed_at' => now()->toIso8601String(),
            'lines_sha256' => InvoiceIssuanceContext::linesHash($row->lines),
            'currency' => 'MAD', 'tax_basis' => 'TTC', 'customer_id' => $customer->user_id,
            'customer' => ['name' => 'Client de test', 'address' => 'Adresse de test'],
            'payment_terms' => ['mode' => 'test', 'label' => $terms],
        ]], JSON_THROW_ON_ERROR)]);
    }

    protected function realDeliveryOrder(User $actor, CustomerProfile $customer, int $quantity = 5, string $price = '4.25'): array
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $balance = StockBalance::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'physical' => $quantity + 5, 'reserved' => 0]);
        $lineId = (string) Str::uuid();
        $orderId = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(), 'status' => 'CONFIRMEE', 'version' => 1,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}',
            'lines' => json_encode([['id' => $lineId, 'product_id' => $product->id, 'quantity' => $quantity, 'units_per_package' => 1, 'unit_price' => $price, 'pricing' => ['currency' => 'MAD', 'tax_basis' => 'TTC']]], JSON_THROW_ON_ERROR),
            'catalogue_subtotal' => bcmul($price, (string) $quantity, 2), 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(WarehouseStock::class)->reserve($actor, $orderId, 1, [
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'line_id' => $lineId, 'quantity' => $quantity],
        ], (string) Str::uuid()));
        $fixture = ['order_id' => $orderId, 'order_line_id' => $lineId, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'sku' => $product->sku, 'balance_id' => $balance->id];
        $this->confirmBilling($fixture, $customer);

        return $fixture;
    }

    protected function dispatchQuantity(User $actor, array $order, int $quantity): array
    {
        $service = app(DeliveryPreparation::class);
        $batch = $service->claim($actor, ['order_id' => $order['order_id'], 'warehouse_id' => $order['warehouse_id']]);
        for ($index = 0; $index < $quantity; $index++) {
            $service->scan($actor, $batch['id'], ['code' => $order['sku'], 'mode' => 'reference'], (string) Str::uuid());
        }
        $service->validatePreparation($actor, $batch['id'], ['confirm_partial' => true]);
        $service->dispatch($actor, $batch['id']);
        $lineId = DB::table('delivery_batch_lines')->where('batch_id', $batch['id'])->value('id');

        return array_merge($order, ['id' => $batch['id'], 'line_id' => $lineId]);
    }

    protected function invoiceInput(CustomerProfile $customer, array $selections): array
    {
        $preview = $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => $selections])
            ->assertOk()->assertJsonPath('data.issuance.ready', true)->json('data');

        return ['customer_id' => $customer->user_id, 'lines' => $selections, 'issuance_token' => $preview['issuance']['token'], 'request_key' => (string) Str::uuid()];
    }

    protected function stockState(): array
    {
        $state = [];
        foreach (['stock_balances', 'stock_reservations', 'stock_movements', 'stock_operations', 'delivery_batches', 'delivery_batch_lines', 'customer_orders'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $state;
    }
}
