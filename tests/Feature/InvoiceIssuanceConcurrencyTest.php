<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\InvoiceIssuer;
use App\Services\InvoicePreview;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\InvoiceFixtures;
use Tests\TestCase;
use Throwable;

class InvoiceIssuanceConcurrencyTest extends TestCase
{
    use InvoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork') || ! str_ends_with(DB::connection()->getDatabaseName(), '_verification')) {
            $this->markTestSkipped('Requires pcntl and a disposable MySQL *_verification database.');
        }
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        config(['billing.issuance_enabled' => true, 'billing.policy_version' => 'race-test', 'billing.number_prefix' => 'TEST-',
            'billing.sequence_key' => 'race-test', 'billing.seller' => ['name' => 'Vendeur test', 'address' => 'Adresse test'],
            'billing.tax_presentation' => 'ttc_only', 'billing.tax_note' => 'Regles de test']);
    }

    public function test_competing_requests_do_not_invoice_the_same_quantity_twice(): void
    {
        $this->race(false);
    }

    public function test_simultaneous_retries_return_one_invoice_and_consume_one_number(): void
    {
        $this->race(true);
    }

    public function test_competing_partial_allocations_require_a_fresh_preview(): void
    {
        $this->race(false, false, 2);
    }

    public function test_independent_real_deliveries_receive_distinct_consecutive_numbers(): void
    {
        $this->race(false, true);
    }

    public function test_return_committed_after_invoice_snapshot_is_seen_under_the_delivery_lock(): void
    {
        $actor = $this->employee(['INVOICE_ISSUE', 'INVOICE_READ', 'ORDER_CONFIRM', 'DELIVERY_PREPARE', 'DELIVERY_DEPARTURE_CONFIRM']);
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $order = $this->realDeliveryOrder($actor, $customer);
        $batch = $this->dispatchQuantity($actor, $order, 5);
        $line = app(InvoicePreview::class)->source($actor, $batch['id'])['lines'][0];
        $input = ['customer_id' => $customer->user_id, 'request_key' => (string) Str::uuid(), 'lines' => [
            ['delivery_line_id' => $line['id'], 'quantity' => 5, 'availability_token' => $line['availability_token']],
        ]];
        $input['issuance_token'] = app(InvoicePreview::class)->preview($actor, $input)['issuance']['token'];
        $before = $this->stockState();
        DB::disconnect();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertNotFalse($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            fread($sockets[1], 1);
            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            $notified = false;
            DB::listen(function ($query) use (&$notified, $sockets): void {
                if (! $notified && DB::transactionLevel() > 0 && str_contains($query->sql, 'customer_invoices')) {
                    $notified = true;
                    fwrite($sockets[1], 'S');
                }
            });
            try {
                app(InvoiceIssuer::class)->issue(User::findOrFail($actor->id), $input);
                $reply = ['status' => 201];
            } catch (Throwable $exception) {
                $reply = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 'error' => $exception->getMessage()];
            }
            fwrite($sockets[1], json_encode($reply, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            DB::disconnect();
            exit(0);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);
        DB::reconnect();
        DB::beginTransaction();
        try {
            DB::table('delivery_batches')->where('id', $batch['id'])->lockForUpdate()->firstOrFail();
            $this->allocation($batch['line_id'], 'return_before_invoice', 2);
            fwrite($sockets[0], '1');
            $this->assertSame('S', fread($sockets[0], 1), 'Invoice must establish its read snapshot before the return commits.');
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $reply = json_decode(stream_get_contents($sockets[0]), true, flags: JSON_THROW_ON_ERROR);
        fclose($sockets[0]);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame(409, $reply['status'], $reply['error'] ?? '');
        $this->assertDatabaseCount('customer_invoices', 0);
        $this->assertDatabaseCount('invoice_sequences', 0);
        $this->assertSame(2, (int) DB::table('billing_quantity_allocations')->sum('quantity'));
        $this->assertSame(3, app(InvoicePreview::class)->source($actor, $batch['id'])['lines'][0]['remaining_quantity']);
        $this->assertSame($before, $this->stockState());
    }

    private function race(bool $sameKey, bool $independent = false, int $quantity = 5): void
    {
        $actor = $this->employee(['INVOICE_ISSUE', 'INVOICE_READ', 'ORDER_CONFIRM', 'DELIVERY_PREPARE', 'DELIVERY_DEPARTURE_CONFIRM']);
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $order = $this->realDeliveryOrder($actor, $customer);
        $batch = $this->dispatchQuantity($actor, $order, 5);
        $line = app(InvoicePreview::class)->source($actor, $batch['id'])['lines'][0];
        $input = ['customer_id' => $customer->user_id, 'request_key' => (string) Str::uuid(), 'lines' => [
            ['delivery_line_id' => $line['id'], 'quantity' => $quantity, 'availability_token' => $line['availability_token']],
        ]];
        $input['issuance_token'] = app(InvoicePreview::class)->preview($actor, $input)['issuance']['token'];
        $inputs = [$input, $input];
        if ($independent) {
            $secondOrder = $this->realDeliveryOrder($actor, $customer);
            $secondBatch = $this->dispatchQuantity($actor, $secondOrder, 5);
            $secondLine = app(InvoicePreview::class)->source($actor, $secondBatch['id'])['lines'][0];
            $inputs[1] = ['customer_id' => $customer->user_id, 'request_key' => (string) Str::uuid(), 'lines' => [
                ['delivery_line_id' => $secondLine['id'], 'quantity' => 5, 'availability_token' => $secondLine['availability_token']],
            ]];
            $inputs[1]['issuance_token'] = app(InvoicePreview::class)->preview($actor, $inputs[1])['issuance']['token'];
        }
        $before = $this->stockState();
        DB::disconnect();
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertNotFalse($sockets);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                $input = $inputs[$index];
                if (! $sameKey) {
                    $input['request_key'] = (string) Str::uuid();
                }
                try {
                    $result = app(InvoiceIssuer::class)->issue(User::findOrFail($actor->id), $input);
                    $reply = ['status' => $result['replayed'] ? 200 : 201, 'id' => $result['invoice']->id];
                } catch (Throwable $exception) {
                    $reply = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 'error' => $exception->getMessage()];
                }
                fwrite($sockets[1], json_encode($reply, JSON_THROW_ON_ERROR));
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
        $ids = [];
        foreach ($children as $child) {
            $reply = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertContains($reply['status'], [200, 201, 409], $reply['error'] ?? '');
            $statuses[] = $reply['status'];
            if (isset($reply['id'])) {
                $ids[] = $reply['id'];
            }
        }
        DB::reconnect();
        sort($statuses);
        $this->assertSame($independent ? [201, 201] : ($sameKey ? [200, 201] : [201, 409]), $statuses);
        $this->assertCount($independent ? 2 : 1, array_unique($ids));
        $this->assertDatabaseCount('customer_invoices', $independent ? 2 : 1);
        $this->assertDatabaseCount('billing_quantity_allocations', $independent ? 2 : 1);
        $this->assertSame($independent ? 10 : $quantity, (int) DB::table('billing_quantity_allocations')->sum('quantity'));
        $this->assertSame($independent ? 3 : 2, (int) DB::table('invoice_sequences')->value('next_number'));
        $this->assertSame($before, $this->stockState());
        $this->assertSame($independent ? 2 : 1, DB::table('audit_events')->where('action', 'invoice.issued')->count());
    }
}
