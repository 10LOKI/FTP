<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\WarehouseStock;
use Database\Seeders\AccessPermissionSeeder;
use Database\Seeders\WarehouseStockDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WarehouseStockTest extends TestCase
{
    use DatabaseMigrations;

    private function employee(array $codes = ['STOCK_VIEW', 'STOCK_RECEIVE', 'STOCK_ADJUST', 'STOCK_TRANSFER', 'STOCK_STRUCTURE_MANAGE', 'ORDER_CONFIRM', 'RESERVATION_CANCEL']): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        foreach ($codes as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'stock', 'label' => $code]);
            $role->permissions()->attach($permission->id);
        }
        $this->actingAs($user);

        return $user;
    }

    private function values(?Warehouse $warehouse = null, ?Product $product = null): array
    {
        return ['warehouse_id' => ($warehouse ?? Warehouse::factory()->create())->id, 'product_id' => ($product ?? Product::factory()->create())->id, 'expected_version' => 0, 'quantity' => 10, 'bucket' => 'physical', 'reason' => 'Réception achat', 'source_reference' => 'ACH-0001'];
    }

    private function mutate(string $action, array $values, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/stock/actions/'.$action, $values, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_guest_customer_and_employee_without_permission_cannot_read_or_change_stock(): void
    {
        $values = $this->values();
        $this->getJson('/api/v1/stock/balances')->assertUnauthorized();
        $this->mutate('receive', $values)->assertUnauthorized();
        $user = $this->employee([]);
        $this->getJson('/api/v1/stock/balances')->assertForbidden();
        $this->mutate('receive', $values)->assertForbidden();
        CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE']);
        $role = $user->roles()->first();
        $permission = Permission::factory()->create(['code' => 'STOCK_VIEW']);
        $role->permissions()->attach($permission->id);
        $this->getJson('/api/v1/stock/balances')->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_receipt_is_atomic_audited_and_replay_does_not_repeat_it(): void
    {
        $actor = $this->employee();
        $values = $this->values();
        $key = (string) Str::uuid();
        $first = $this->mutate('receive', $values, $key)->assertOk();
        $second = $this->mutate('receive', array_reverse($values, true), $key)->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseHas('stock_balances', ['physical' => 10, 'reserved' => 0, 'version' => 1]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_operations', 1);
        $audit = AuditEvent::where('action', 'stock.receipt')->sole();
        $this->assertEquals($actor->id, $audit->actor_id);
        $this->assertSame(10, $audit->getAttribute('changes')['after']['physical']);
        $movement = DB::table('stock_movements')->sole();
        $this->assertSame('ACH-0001', $movement->source_reference);
        $this->assertSame('Réception achat', $movement->reason);
        $this->assertSame(10, (int) $movement->physical_delta);
        $this->assertNotNull($movement->created_at);
        $values['quantity'] = 20;
        $this->mutate('receive', $values, $key)->assertConflict();
        $actor->roles()->first()->permissions()->detach();
        $this->mutate('receive', $values, $key)->assertForbidden();
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_validation_rejects_unknown_fields_reserved_receipts_zero_and_missing_key(): void
    {
        $this->employee();
        $values = $this->values();
        $this->postJson('/api/v1/stock/actions/receive', $values)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        foreach ([['bucket' => 'reserved'], ['quantity' => 0], ['quantity' => 1.2], ['physical' => 999], ['reason' => '']] as $invalid) {
            $this->mutate('receive', [...$values, ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_adjustment_rejects_stale_versions_negative_stock_and_reserved_floor(): void
    {
        $this->employee();
        $values = $this->values();
        $this->mutate('receive', $values)->assertOk();
        $balance = StockBalance::sole();
        $balance->update(['reserved' => 4]);
        $this->mutate('adjust', [...$values, 'quantity' => -7, 'expected_version' => 1])->assertConflict();
        $this->mutate('adjust', [...$values, 'quantity' => -1])->assertConflict();
        $this->mutate('adjust', [...$values, 'quantity' => -6, 'expected_version' => 1])->assertOk();
        $this->assertSame(4, $balance->fresh()->physical);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('stock_operations', 2);
    }

    public function test_control_stock_requires_inspection_before_becoming_available(): void
    {
        $this->employee();
        $values = $this->values();
        $this->mutate('receive', [...$values, 'bucket' => 'control'])->assertOk();
        $this->getJson('/api/v1/stock/balances')->assertOk()->assertJsonPath('data.0.available', 0)->assertJsonPath('data.0.control', 10);
        unset($values['bucket']);
        $this->mutate('inspect', [...$values, 'quantity' => 6, 'destination' => 'physical', 'expected_version' => 1])->assertOk();
        $this->mutate('inspect', [...$values, 'quantity' => 4, 'destination' => 'rejected', 'expected_version' => 2])->assertOk();
        $this->assertDatabaseHas('stock_balances', ['physical' => 6, 'control' => 0, 'rejected' => 4]);
        $this->mutate('inspect', [...$values, 'quantity' => 1, 'destination' => 'physical', 'expected_version' => 3])->assertConflict();
    }

    public function test_transfers_conserve_totals_link_movements_and_leave_reservations_at_source(): void
    {
        $this->employee();
        $values = $this->values();
        $this->mutate('receive', $values)->assertOk();
        $source = StockBalance::sole();
        $source->update(['reserved' => 4]);
        $destination = Warehouse::factory()->create();
        unset($values['bucket']);
        $transfer = [...$values, 'destination_warehouse_id' => $destination->id, 'destination_expected_version' => 0, 'expected_version' => 1, 'quantity' => 7];
        $this->mutate('transfer', $transfer)->assertConflict();
        $transfer['quantity'] = 6;
        $key = (string) Str::uuid();
        $result = $this->mutate('transfer', $transfer, $key)->assertOk();
        $this->mutate('transfer', $transfer, $key)->assertOk()->assertExactJson($result->json());
        $this->assertSame(10, (int) StockBalance::sum('physical'));
        $this->assertSame(4, $source->fresh()->reserved);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $destination->id, 'physical' => 6, 'reserved' => 0]);
        $movements = DB::table('stock_movements')->where('operation_id', $result->json('data.operation_id'))->get();
        $this->assertCount(2, $movements);
        $this->assertSame(0, (int) $movements->sum('physical_delta'));
    }

    public function test_failure_on_second_transfer_audit_rolls_back_both_depots_and_ledger(): void
    {
        $this->employee();
        $values = $this->values();
        $this->mutate('receive', $values)->assertOk();
        $destination = Warehouse::factory()->create();
        unset($values['bucket']);
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'stock.transfer_in') {
                throw new RuntimeException('simulated audit storage failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->mutate('transfer', [...$values, 'destination_warehouse_id' => $destination->id, 'destination_expected_version' => 0, 'expected_version' => 1, 'quantity' => 3]);
            $this->fail('Transfer must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit storage failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('stock_balances', 1);
        $this->assertSame(10, StockBalance::sole()->physical);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'stock.transfer%')->count());
    }

    public function test_structure_location_and_threshold_are_versioned_and_depot_scoped(): void
    {
        $this->employee();
        $warehouseId = $this->mutate('warehouse', ['code' => 'D-01', 'name' => 'Casablanca', 'active' => true])->assertOk()->json('data.warehouse_id');
        $locationId = $this->mutate('location', ['warehouse_id' => $warehouseId, 'code' => 'A-01', 'aisle' => 'A', 'level' => '2'])->assertOk()->json('data.location_id');
        $foreignLocation = WarehouseLocation::factory()->create();
        $values = $this->values(Warehouse::findOrFail($warehouseId));
        unset($values['reason'], $values['source_reference'], $values['quantity'], $values['bucket']);
        $configure = [...$values, 'location_id' => $foreignLocation->id, 'threshold_quantity' => 20, 'threshold_basis' => 'available'];
        $this->mutate('configure', $configure)->assertUnprocessable();
        $configure['location_id'] = $locationId;
        $this->mutate('configure', $configure)->assertOk();
        $this->assertDatabaseHas('stock_balances', ['location_id' => $locationId, 'threshold_quantity' => 20, 'version' => 1]);
        $this->mutate('configure', $configure)->assertConflict();
        $this->getJson('/api/v1/stock/locations?warehouse_id='.$warehouseId)->assertOk()->assertJsonCount(1, 'data');
        $this->mutate('warehouse', ['warehouse_id' => $warehouseId, 'expected_version' => 1, 'code' => 'D-01', 'name' => 'Casa', 'active' => false])->assertOk();
        $this->mutate('receive', [...$this->values(Warehouse::findOrFail($warehouseId), Product::findOrFail($values['product_id'])), 'expected_version' => 1])->assertConflict();
    }

    public function test_alerts_are_durable_depot_specific_deduplicated_and_reopen_after_replenishment(): void
    {
        $this->employee();
        $values = $this->values();
        $this->mutate('receive', $values)->assertOk();
        $config = array_intersect_key($values, array_flip(['warehouse_id', 'product_id']));
        $config += ['expected_version' => 1, 'location_id' => null, 'threshold_quantity' => 20, 'threshold_basis' => 'available'];
        $this->mutate('configure', $config)->assertOk();
        $this->mutate('receive', [...$values, 'quantity' => 1, 'expected_version' => 2])->assertOk();
        $this->assertDatabaseCount('stock_alerts', 1);
        $this->getJson('/api/v1/stock/alerts?warehouse_id='.$values['warehouse_id'])->assertOk()->assertJsonPath('data.0.observed_quantity', 11)->assertJsonPath('data.0.threshold', 20);
        $other = Warehouse::factory()->create();
        $this->getJson('/api/v1/stock/alerts?warehouse_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->mutate('receive', [...$values, 'quantity' => 20, 'expected_version' => 3])->assertOk();
        $this->getJson('/api/v1/stock/alerts')->assertJsonCount(0, 'data');
        $this->mutate('adjust', [...$values, 'quantity' => -11, 'expected_version' => 4])->assertOk();
        $this->assertDatabaseCount('stock_alerts', 2);
        $this->assertSame(1, DB::table('stock_alerts')->whereNotNull('active_balance_id')->count());
    }

    public function test_stock_reads_do_not_expose_costs_credentials_or_internal_product_fields(): void
    {
        $this->employee(['STOCK_VIEW']);
        $product = Product::factory()->create(['net_purchase_price' => '123.45']);
        StockBalance::factory()->create(['product_id' => $product->id, 'physical' => 8, 'reserved' => 3]);
        $this->getJson('/api/v1/stock/balances')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.available', 5)->assertJsonMissingPath('data.0.product.net_purchase_price');
        $this->getJson('/api/v1/stock/products')->assertOk()->assertJsonMissingPath('data.0.net_purchase_price')->assertDontSee('123.45');
        $this->mutate('receive', $this->values())->assertForbidden();
    }

    public function test_permission_catalog_and_demo_are_repeatable_and_grant_no_roles(): void
    {
        $this->seed([AccessPermissionSeeder::class, WarehouseStockDemoSeeder::class]);
        $this->seed([AccessPermissionSeeder::class, WarehouseStockDemoSeeder::class]);
        $this->assertDatabaseCount('warehouses', 2);
        $this->assertDatabaseCount('warehouse_locations', 2);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseHas('permissions', ['code' => 'STOCK_ADJUST']);
    }

    private function order(Product $product, int $quantity = 3, string $status = 'CONFIRMEE'): array
    {
        $lineId = (string) Str::uuid();
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $orderId = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::uuid(), 'status' => $status, 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}', 'lines' => json_encode([['id' => $lineId, 'product_id' => (string) $product->id, 'quantity' => $quantity, 'units_per_package' => 1]], JSON_THROW_ON_ERROR), 'catalogue_subtotal' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

        return [$orderId, $lineId];
    }

    public function test_reservations_report_shortages_without_changing_physical_and_replay_once(): void
    {
        $actor = $this->employee();
        $balance = StockBalance::factory()->create(['physical' => 2]);
        [$orderId, $lineId] = $this->order($balance->product);
        $lines = [['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'line_id' => $lineId, 'quantity' => 3]];
        $key = (string) Str::uuid();
        $reserve = fn () => DB::transaction(fn () => app(WarehouseStock::class)->reserve($actor, $orderId, 1, $lines, $key));
        $result = $reserve();
        $this->assertSame($result, $reserve());
        $this->assertSame(2, $result['allocations'][0]['reserved']);
        $this->assertSame(1, $result['allocations'][0]['shortage']);
        $this->assertSame(2, $balance->fresh()->physical);
        $this->assertSame(2, $balance->fresh()->reserved);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_cancel_release_and_order_state_roll_back_together_and_do_not_increase_physical(): void
    {
        $actor = $this->employee();
        $balance = StockBalance::factory()->create(['physical' => 10]);
        [$orderId, $lineId] = $this->order($balance->product);
        DB::transaction(fn () => app(WarehouseStock::class)->reserve($actor, $orderId, 1, [['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'line_id' => $lineId, 'quantity' => 3]], (string) Str::uuid()));
        try {
            DB::transaction(function () use ($actor, $orderId): void {
                app(WarehouseStock::class)->releaseCancelledOrder($actor, $orderId, 1, (string) Str::uuid());
            });
            $this->fail('Ready order must not lose its reservation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $release = function () use ($actor, $orderId): void {
            DB::table('customer_orders')->where('id', $orderId)->update(['status' => 'ANNULEE', 'version' => 2]);
            app(WarehouseStock::class)->releaseCancelledOrder($actor, $orderId, 2, (string) Str::uuid());
        };
        try {
            DB::transaction(function () use ($release): void {
                $release();
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertDatabaseHas('customer_orders', ['id' => $orderId, 'status' => 'CONFIRMEE']);
        $this->assertSame(3, $balance->fresh()->reserved);
        DB::transaction($release);
        $this->assertSame(0, $balance->fresh()->reserved);
        $this->assertSame(10, $balance->fresh()->physical);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_same_depot_transfer_is_rejected_even_with_different_uuid_case(): void
    {
        $this->employee();
        $values = $this->values();
        $this->mutate('receive', $values)->assertOk();
        unset($values['bucket']);
        $this->mutate('transfer', [...$values, 'warehouse_id' => strtoupper($values['warehouse_id']), 'destination_warehouse_id' => strtolower($values['warehouse_id']), 'expected_version' => 1, 'destination_expected_version' => 1])->assertStatus(422);
        $this->assertSame(10, StockBalance::sole()->physical);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_alert_opens_when_reservation_reaches_available_threshold_and_closes_on_release(): void
    {
        $actor = $this->employee();
        $balance = StockBalance::factory()->create(['physical' => 10]);
        $this->mutate('configure', ['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'expected_version' => 0, 'location_id' => null, 'threshold_quantity' => 7, 'threshold_basis' => 'available'])->assertOk();
        $this->assertDatabaseCount('stock_alerts', 0);
        [$orderId, $lineId] = $this->order($balance->product);
        DB::transaction(fn () => app(WarehouseStock::class)->reserve($actor, $orderId, 1, [['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'line_id' => $lineId, 'quantity' => 3]], (string) Str::uuid()));
        $this->assertDatabaseHas('stock_alerts', ['active_balance_id' => $balance->id, 'observed_quantity' => 7]);
        DB::transaction(function () use ($actor, $orderId): void {
            DB::table('customer_orders')->where('id', $orderId)->update(['status' => 'ANNULEE', 'version' => 2]);
            app(WarehouseStock::class)->releaseCancelledOrder($actor, $orderId, 2, (string) Str::uuid());
        });
        $this->assertSame(0, DB::table('stock_alerts')->whereNotNull('active_balance_id')->count());
        $this->assertSame(10, $balance->fresh()->physical);
    }

    public function test_threshold_basis_is_available_only_and_cannot_be_partially_configured(): void
    {
        $this->employee();
        $values = array_intersect_key($this->values(), array_flip(['warehouse_id', 'product_id', 'expected_version']));
        $config = [...$values, 'location_id' => null, 'threshold_quantity' => 20, 'threshold_basis' => 'physical'];
        $this->mutate('configure', $config)->assertUnprocessable()->assertJsonValidationErrors('threshold_basis');
        $this->mutate('configure', [...$config, 'threshold_basis' => null])->assertUnprocessable();
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_persisted_movements_cannot_be_rewritten_or_deleted(): void
    {
        $this->employee();
        $this->mutate('receive', $this->values())->assertOk();
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    DB::table('stock_movements')->update(['reason' => 'changed']);
                } else {
                    DB::table('stock_movements')->delete();
                }
                $this->fail('Ledger must be immutable.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('immutable', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_order_stock_contract_refuses_to_run_outside_the_callers_transaction(): void
    {
        $actor = $this->employee();
        $this->expectException(LogicException::class);
        app(WarehouseStock::class)->reserve($actor, 1, 1, [], (string) Str::uuid());
    }
}
