<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryPreparation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class DeliveryPreparationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork') || ! str_ends_with(DB::connection()->getDatabaseName(), '_verification')) {
            $this->markTestSkipped('Requires pcntl and a disposable MySQL *_verification database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    public function test_two_preparers_cannot_claim_the_same_delivery_note(): void
    {
        $actors = [$this->employee(), $this->employee()];
        [$orderId, $warehouseId] = $this->reservedOrder($actors[0]);
        DB::disconnect();
        $children = [];
        foreach ($actors as $actor) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertNotFalse($sockets);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                try {
                    app(DeliveryPreparation::class)->claim(User::findOrFail($actor->id), ['order_id' => $orderId, 'warehouse_id' => $warehouseId]);
                    $result = ['status' => 200];
                } catch (Throwable $exception) {
                    $result = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 'error' => $exception->getMessage()];
                }
                fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR));
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
        $statuses = [];
        foreach ($children as $child) {
            $result = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertContains($result['status'], [200, 409], $result['error'] ?? '');
            $statuses[] = $result['status'];
        }
        DB::reconnect();
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('delivery_batches', 1);
    }

    private function employee(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        $permission = Permission::firstOrCreate(['code' => 'DELIVERY_PREPARE'], ['module' => 'delivery', 'label' => 'Prepare deliveries']);
        $role->permissions()->attach($permission->id);

        return $user;
    }

    /** @return array{int, string} */
    private function reservedOrder(User $actor): array
    {
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $balance = StockBalance::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'physical' => 1, 'reserved' => 1]);
        $lineId = (string) Str::uuid();
        $orderId = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(), 'status' => 'CONFIRMEE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}', 'lines' => json_encode([['id' => $lineId, 'product_id' => $product->id, 'quantity' => 1, 'units_per_package' => 1]], JSON_THROW_ON_ERROR), 'catalogue_subtotal' => '1.00', 'created_at' => now(), 'updated_at' => now()]);
        $operation = (string) Str::uuid();
        DB::table('stock_operations')->insert(['id' => $operation, 'request_key' => (string) Str::uuid(), 'actor_id' => $actor->id, 'action' => 'reserve', 'request_hash' => str_repeat('b', 64), 'result' => '{}', 'created_at' => now('UTC')]);
        DB::table('stock_reservations')->insert(['id' => (string) Str::uuid(), 'order_id' => $orderId, 'line_id' => $lineId, 'balance_id' => $balance->id, 'quantity' => 1, 'order_version' => 1, 'operation_id' => $operation, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return [$orderId, $warehouse->id];
    }
}
