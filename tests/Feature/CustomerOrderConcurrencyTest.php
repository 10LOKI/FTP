<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\CustomerOrderService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class CustomerOrderConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real concurrent transactions require MySQL and pcntl (available in Docker).');
        }
    }

    public function test_simultaneous_replay_creates_exactly_one_order_and_one_submission_audit(): void
    {
        [$userId, $payload] = $this->prepareCart();
        $results = $this->submitConcurrently($userId, [$payload, $payload]);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $replayed = array_column($results, 'replayed');
        sort($replayed);
        $this->assertSame([false, true], $replayed);
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('cart_lines', 0);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'order.submitted')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'order.replayed')->count());
    }

    public function test_two_different_requests_cannot_consume_the_same_cart_twice(): void
    {
        [$userId, $payload] = $this->prepareCart();
        $other = array_replace($payload, ['idempotency_key' => (string) Str::uuid()]);
        $results = $this->submitConcurrently($userId, [$payload, $other]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('cart_lines', 0);
        $this->assertSame(2, (int) DB::table('carts')->value('version'));
    }

    public function test_price_committed_after_request_lookup_is_revalidated_before_submission(): void
    {
        [$userId, $payload] = $this->prepareCart();
        $productId = (int) DB::table('cart_lines')->value('product_id');
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $productId): void {
            if ($changed || ! str_contains($query->sql, 'customer_orders') || ! str_contains($query->sql, 'idempotency_key')) {
                return;
            }
            $changed = true;
            $result = Concurrency::run([static function () use ($productId): int {
                return DB::table('products')->where('id', $productId)->update(['sale_price' => '16.00']);
            }]);
            $this->assertSame(1, $result[0]);
        });
        try {
            app(CustomerOrderService::class)->submit($userId, $payload, (string) Str::uuid());
            $this->fail('A concurrently changed price must require another review.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertTrue($changed);
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertDatabaseHas('products', ['id' => $productId, 'sale_price' => '16.00']);
    }

    /** @return array{int, array<string, mixed>} */
    private function prepareCart(): array
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '12.50']);
        $cart = app(CartService::class)->putLine($user->id, $product->id, 2, 1, 0, (string) Str::uuid());
        $address = ['address' => 'Test concurrence', 'city' => 'Rabat', 'country' => 'Maroc'];

        return [$user->id, [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => 1,
            'pricing_token' => CartService::pricingToken($cart),
            'contact' => ['name' => 'Client', 'email' => 'client@example.test', 'phone' => '0612345678'],
            'delivery_address' => $address, 'billing_address' => $address,
        ]];
    }

    /** @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>>
     */
    private function submitConcurrently(int $userId, array $payloads): array
    {
        DB::disconnect();
        $children = [];
        foreach ($payloads as $payload) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertNotFalse($pair);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($pair[0]);
                fread($pair[1], 1);
                try {
                    $result = app(CustomerOrderService::class)->submit($userId, $payload, (string) Str::uuid());
                    $output = ['id' => (string) $result['order']->id, 'replayed' => $result['replayed'], 'status' => $result['replayed'] ? 200 : 201];
                } catch (Throwable $exception) {
                    $output = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 'error' => $exception->getMessage()];
                }
                fwrite($pair[1], json_encode($output, JSON_THROW_ON_ERROR));
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
