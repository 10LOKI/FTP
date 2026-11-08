<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class WarehouseStockConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork') || ! str_ends_with(DB::connection()->getDatabaseName(), '_verification')) {
            $this->markTestSkipped('Requires pcntl and a disposable MySQL *_verification database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    private function employee(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        foreach (['STOCK_RECEIVE', 'STOCK_TRANSFER', 'STOCK_ADJUST', 'ORDER_CONFIRM'] as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'stock', 'label' => $code]);
            $role->permissions()->attach($permission->id);
        }

        return $user;
    }

    public function test_concurrent_replay_creates_one_balance_movement_and_audit(): void
    {
        $actor = $this->employee();
        $balance = StockBalance::factory()->create();
        $values = ['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'quantity' => 10, 'bucket' => 'physical', 'expected_version' => 0, 'reason' => 'Achat', 'source_reference' => 'ACH-1'];
        $operation = ['actor_id' => $actor->id, 'action' => 'receive', 'values' => $values, 'key' => (string) Str::uuid()];
        $this->assertSame([200, 200], $this->race([$operation, $operation]));
        $this->assertSame(10, $balance->fresh()->physical);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_operations', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_competing_transfers_cannot_take_the_same_available_units(): void
    {
        $actor = $this->employee();
        $balance = StockBalance::factory()->create(['physical' => 10, 'reserved' => 4]);
        $operations = [];
        foreach (range(1, 2) as $ignored) {
            $operations[] = ['actor_id' => $actor->id, 'action' => 'transfer', 'key' => (string) Str::uuid(), 'values' => ['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'quantity' => 6, 'expected_version' => 0, 'destination_expected_version' => 0, 'destination_warehouse_id' => Warehouse::factory()->create()->id, 'reason' => 'Rééquilibrage', 'source_reference' => 'TR-1']];
        }
        $this->assertSame([200, 409], $this->race($operations));
        $this->assertSame(10, (int) StockBalance::sum('physical'));
        $this->assertSame(4, $balance->fresh()->physical);
        $this->assertSame(4, $balance->fresh()->reserved);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_two_orders_compete_for_the_last_unit_without_over_reserving(): void
    {
        $actor = $this->employee();
        $balance = StockBalance::factory()->create(['physical' => 1]);
        $operations = [];
        foreach (range(1, 2) as $ignored) {
            $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
            $lineId = (string) Str::uuid();
            $id = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::uuid(), 'status' => 'CONFIRMEE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}', 'lines' => json_encode([['id' => $lineId, 'product_id' => (string) $balance->product_id, 'quantity' => 1, 'units_per_package' => 1]], JSON_THROW_ON_ERROR), 'catalogue_subtotal' => '1.00', 'created_at' => now(), 'updated_at' => now()]);
            $operations[] = ['actor_id' => $actor->id, 'action' => 'reserve', 'order_id' => $id, 'key' => (string) Str::uuid(), 'values' => [['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'quantity' => 1, 'line_id' => $lineId]]];
        }
        $results = $this->race($operations, true);
        $this->assertSame([0, 1], $results);
        $this->assertSame(1, $balance->fresh()->physical);
        $this->assertSame(1, $balance->fresh()->reserved);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_opposite_direction_transfers_have_stable_locks_and_conserve_stock(): void
    {
        $actor = $this->employee();
        $first = StockBalance::factory()->create(['physical' => 10]);
        $second = StockBalance::factory()->create(['product_id' => $first->product_id, 'physical' => 10]);
        $operations = [];
        foreach ([[$first, $second], [$second, $first]] as [$from, $to]) {
            $operations[] = ['actor_id' => $actor->id, 'action' => 'transfer', 'key' => (string) Str::uuid(), 'values' => ['warehouse_id' => $from->warehouse_id, 'destination_warehouse_id' => $to->warehouse_id, 'product_id' => $first->product_id, 'quantity' => 3, 'expected_version' => 0, 'destination_expected_version' => 0, 'reason' => 'Rééquilibrage', 'source_reference' => 'TR-2']];
        }
        $this->assertSame([200, 409], $this->race($operations));
        $this->assertSame(20, (int) StockBalance::sum('physical'));
        $this->assertSame(0, (int) DB::table('stock_movements')->sum('physical_delta'));
    }

    /** @param list<array<string, mixed>> $operations
     * @return list<int>
     */
    private function race(array $operations, bool $returnShortages = false): array
    {
        DB::disconnect();
        $children = [];
        foreach ($operations as $operation) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertNotFalse($sockets);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                try {
                    $actor = User::findOrFail($operation['actor_id']);
                    if ($operation['action'] === 'reserve') {
                        $result = DB::transaction(fn () => app(WarehouseStock::class)->reserve($actor, $operation['order_id'], 1, $operation['values'], $operation['key']));
                    } else {
                        $result = app(WarehouseStock::class)->execute($actor, $operation['action'], $operation['values'], $operation['key']);
                    }
                    $response = ['status' => 200, 'shortage' => $result['allocations'][0]['shortage'] ?? null];
                } catch (Throwable $exception) {
                    $response = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 'error' => $exception->getMessage()];
                }
                fwrite($sockets[1], json_encode($response, JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                DB::disconnect();
                exit(0);
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 30);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }
        foreach ($children as $child) {
            fwrite($child['socket'], '1');
        }
        $results = [];
        foreach ($children as $child) {
            $result = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertContains($result['status'], [200, 409], $result['error'] ?? '');
            $results[] = $returnShortages ? $result['shortage'] : $result['status'];
        }
        DB::reconnect();
        sort($results);

        return $results;
    }
}
