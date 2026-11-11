<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesOrderReview;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class SalesReviewConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the dedicated MySQL test database and pcntl in Docker.');
        }
    }

    private function actor(): int
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::firstOrCreate(['code' => 'ORDER_REVIEW_MANAGE'], ['module' => 'sales', 'label' => 'Review']);
        $role->permissions()->attach($permission);
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);

        return $actor->id;
    }

    private function order(): int
    {
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);

        return DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(),
            'status' => 'SOUMISE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}',
            'lines' => '[]', 'catalogue_subtotal' => '10.00', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_simultaneous_claim_has_one_owner_and_one_transition(): void
    {
        $id = $this->order();
        $first = $this->actor();
        $second = $this->actor();
        $results = $this->parallel($id, [
            ['actor' => $first, 'method' => 'claim', 'values' => []],
            ['actor' => $second, 'method' => 'claim', 'values' => []],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('sales_order_reviews', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'status' => 'EN_VERIFICATION', 'version' => 2]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.order_claimed')->count());
    }

    public function test_simultaneous_call_replay_records_once(): void
    {
        $id = $this->order();
        $actor = $this->actor();
        app(SalesOrderReview::class)->claim(User::findOrFail($actor), $id, 1);
        $values = ['expected_version' => 2, 'request_key' => (string) Str::uuid(), 'outcome' => 'JOINED',
            'happened_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'), 'note' => 'Concurrent test'];
        $task = ['actor' => $actor, 'method' => 'recordCall', 'values' => $values];
        $results = $this->parallel($id, [$task, $task]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $replays = array_column(array_column($results, 'data'), 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);
        $this->assertDatabaseCount('sales_order_calls', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 3]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.call_recorded')->count());
    }

    public function test_competing_revisions_reject_stale_version_and_preserve_original(): void
    {
        $id = $this->order();
        $actor = $this->actor();
        app(SalesOrderReview::class)->claim(User::findOrFail($actor), $id, 1);
        $values = ['expected_version' => 2, 'request_key' => (string) Str::uuid(), 'kind' => 'contact',
            'changes' => ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '0000000000'], 'reason' => 'Test'];
        $results = $this->parallel($id, [
            ['actor' => $actor, 'method' => 'proposeRevision', 'values' => $values],
            ['actor' => $actor, 'method' => 'proposeRevision', 'values' => array_replace($values, ['request_key' => (string) Str::uuid()])],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('sales_order_revisions', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 3]);
        $this->assertSame('{}', DB::table('customer_orders')->where('id', $id)->value('contact'));
        $this->assertDatabaseCount('stock_operations', 0);
    }

    /** @param list<array{actor: int, method: string, values: array<string, mixed>}> $tasks
     * @return list<array<string, mixed>>
     */
    private function parallel(int $id, array $tasks): array
    {
        DB::disconnect();
        $children = [];
        foreach ($tasks as $task) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertNotFalse($pair);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($pair[0]);
                fread($pair[1], 1);
                try {
                    $actor = User::findOrFail($task['actor']);
                    $service = app(SalesOrderReview::class);
                    $data = $task['method'] === 'claim' ? $service->claim($actor, $id, 1) : $service->{$task['method']}($actor, $id, $task['values']);
                    $result = ['status' => 200, 'data' => $data];
                } catch (Throwable $exception) {
                    $result = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 'error' => $exception->getMessage()];
                }
                fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR));
                fclose($pair[1]);
                DB::disconnect();
                exit(0);
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 30);
            $children[] = ['pid' => $pid, 'socket' => $pair[0]];
        }
        foreach ($children as $child) {
            fwrite($child['socket'], '1');
        }
        $results = [];
        foreach ($children as $child) {
            $results[] = json_decode(stream_get_contents($child['socket']), true, 512, JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        return $results;
    }
}
