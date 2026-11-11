<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\CustomerFinance;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\InvoiceFixtures;
use Tests\TestCase;
use Throwable;

class CustomerFinanceConcurrencyTest extends TestCase
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
        config(['receivables.enabled' => true]);
    }

    public function test_competing_payments_cannot_overallocate(): void
    {
        $this->race('payment', false);
    }

    public function test_simultaneous_payment_replay_writes_one_entry(): void
    {
        $this->race('payment', true);
    }

    public function test_credit_allocation_racing_refund_consumes_the_right_once(): void
    {
        $this->race('credit', false);
    }

    public function test_cheque_collection_racing_correction_has_one_consistent_balance(): void
    {
        $this->race('cheque', false);
    }

    private function race(string $kind, bool $sameKey): void
    {
        $actor = $this->employee(['PAYMENT_READ', 'PAYMENT_RECORD', 'PAYMENT_CORRECT', 'CUSTOMER_TERMS_MANAGE']);
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $service = app(CustomerFinance::class);
        $service->operate($actor, $customer->user_id, 'terms', ['request_key' => (string) Str::uuid(), 'version' => 1,
            'mode' => $kind === 'cheque' ? 'cheque' : 'cash', 'label' => 'Test', 'reason' => 'Fixture']);
        $batch = $this->delivery($actor, $customer, '10.00', 5);
        $this->confirmBilling($batch, $customer);
        $order = DB::table('customer_orders')->find($batch['order_id']);
        $snapshot = json_decode($order->commercial_snapshot, true);
        $snapshot['billing_v1']['payment_terms'] = ['mode' => 'cash', 'label' => 'Test', 'due_date' => today()->addDays(10)->toDateString()];
        DB::table('customer_orders')->where('id', $order->id)->update(['commercial_snapshot' => json_encode($snapshot)]);
        $state = DB::transaction(fn () => $service->state($customer->user_id));
        $allocation = [['receivable_id' => $state['receivables'][0]['id'], 'amount' => '50.00']];
        $input = ['request_key' => (string) Str::uuid(), 'amount' => '50.00', 'mode' => 'cash', 'payment_date' => today()->toDateString(), 'allocations' => $allocation];
        $actions = ['payment', 'payment'];
        $inputs = [$input, [...$input, 'request_key' => $sameKey ? $input['request_key'] : (string) Str::uuid()]];
        if ($kind === 'credit') {
            $right = DB::transaction(fn () => $service->registerCredit($customer->user_id, 'return:concurrency', '50.00'));
            $actions = ['credit_apply', 'refund'];
            $inputs = [
                ['request_key' => (string) Str::uuid(), 'credit_id' => $right, 'amount' => '50.00', 'allocations' => $allocation, 'reason' => 'Fixture'],
                ['request_key' => (string) Str::uuid(), 'credit_id' => $right, 'amount' => '50.00', 'reference' => 'Refund', 'reason' => 'Fixture'],
            ];
        } elseif ($kind === 'cheque') {
            $payment = $service->operate($actor, $customer->user_id, 'payment', [...$input, 'mode' => 'cheque', 'reference' => 'Cheque', 'maturity_date' => today()->toDateString()]);
            $actions = ['collect', 'reverse'];
            $inputs = [
                ['request_key' => (string) Str::uuid(), 'payment_id' => $payment['id'], 'reference' => 'Bank'],
                ['request_key' => (string) Str::uuid(), 'payment_id' => $payment['id'], 'reason' => 'Correction'],
            ];
        }
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
                try {
                    $result = app(CustomerFinance::class)->operate(User::findOrFail($actor->id), $customer->user_id, $actions[$index], $inputs[$index]);
                    $reply = ['status' => $result['replayed'] ? 200 : 201, 'id' => $result['id']];
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
            $raw = stream_get_contents($child['socket']);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $reply = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertContains($reply['status'], [200, 201, 409], $reply['error'] ?? '');
            $statuses[] = $reply['status'];
            if (isset($reply['id'])) {
                $ids[] = $reply['id'];
            }
        }
        DB::reconnect();
        sort($statuses);
        if ($kind === 'cheque') {
            $this->assertContains($statuses, [[201, 201], [201, 409]]);
        } else {
            $this->assertSame($sameKey ? [200, 201] : [201, 409], $statuses);
            $this->assertCount(1, array_unique($ids));
        }
        $state = DB::transaction(fn () => $service->state($customer->user_id));
        $this->assertSame($kind === 'cheque' ? '50.00' : ($kind === 'credit' && $state['refunds'] === '50.00' ? '50.00' : '0.00'), $state['debt']);
        $this->assertSame('0.00', $state['credit']);
        $this->assertSame('0.00', $state['pending_cheques']);
    }
}
