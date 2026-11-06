<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\NotificationDeliverySender;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use Throwable;

class AuditConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('MySQL and independent processes are required (Docker).');
        }
    }

    public function test_two_delivery_workers_cannot_send_the_same_committed_intent_twice(): void
    {
        config(['notifications.order_received_enabled' => true]);
        $delivery = NotificationDelivery::factory()->create();
        DB::disconnect();
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($pair[0]);
                fread($pair[1], 1);
                try {
                    $result = app(NotificationDeliverySender::class)->send($delivery->id);
                    fwrite($pair[1], json_encode(['sent' => $result]));
                } catch (Throwable $exception) {
                    fwrite($pair[1], json_encode(['error' => $exception->getMessage()]));
                }
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
        $sent = [];
        foreach ($children as $child) {
            $result = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertArrayNotHasKey('error', $result);
            $sent[] = $result['sent'];
        }
        sort($sent);
        $this->assertSame([false, true], $sent);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertSame(1, AuditEvent::where('action', 'notification.delivery')->where('result', 'SUCCESS')->count());
    }

    public function test_critical_product_is_not_visible_from_another_connection_before_its_success_audit(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('PRODUCT_CREATE', fn (): bool => true);
        config(['database.connections.audit_observer' => config('database.connections.mysql')]);
        $observed = false;
        DB::listen(function (QueryExecuted $query) use (&$observed): void {
            if ($observed || ! str_starts_with(strtolower($query->sql), 'insert into `products`')) {
                return;
            }
            $observed = true;
            $this->assertSame(0, DB::connection('audit_observer')->table('products')->where('sku', 'ATOMIC-MYSQL')->count());
        });
        $this->postJson('/api/v1/products', ['sku' => 'ATOMIC-MYSQL', 'designation' => 'Pièce', 'original_reference' => '001'])
            ->assertCreated();
        $this->assertTrue($observed);
        $this->assertSame(1, DB::connection('audit_observer')->table('products')->where('sku', 'ATOMIC-MYSQL')->count());
        $this->assertSame(1, AuditEvent::where('action', 'product.created')->count());
        DB::disconnect('audit_observer');
    }
}
