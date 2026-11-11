<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CustomerFinance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\InvoiceFixtures;
use Tests\TestCase;

class CustomerFinanceTest extends TestCase
{
    use InvoiceFixtures, RefreshDatabase;

    protected User $actor;

    protected CustomerProfile $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['receivables.enabled' => true, 'receivables.reminder_days' => 3]);
        $this->travelTo(now()->setDate(2026, 11, 11)->startOfDay());
        $this->actor = $this->employee(['PAYMENT_READ', 'PAYMENT_RECORD', 'PAYMENT_CORRECT', 'CUSTOMER_TERMS_MANAGE', 'PAYMENT_UNBLOCK']);
        $this->customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($this->actor);
        $this->terms('cash');
    }

    private function url(string $action = ''): string
    {
        return '/api/v1/finance/customers/'.$this->customer->user_id.($action === '' ? '' : '/'.$action);
    }

    private function terms(string $mode): void
    {
        $version = $this->getJson($this->url())->assertOk()->json('data.version');
        $this->postJson($this->url('terms'), ['request_key' => (string) Str::uuid(), 'version' => $version, 'mode' => $mode, 'label' => 'Conditions confirmées', 'reason' => 'Décision responsable'])->assertCreated();
    }

    private function debt(string $due = '2026-11-20', int $quantity = 5, string $price = '10.00', string $mode = 'cash'): array
    {
        $batch = $this->delivery($this->actor, $this->customer, $price, $quantity);
        $this->confirmBilling($batch, $this->customer);
        $order = DB::table('customer_orders')->find($batch['order_id']);
        $snapshot = json_decode($order->commercial_snapshot, true);
        $snapshot['billing_v1']['payment_terms'] = ['mode' => $mode, 'label' => 'Conditions historiques', 'due_date' => $due];
        DB::table('customer_orders')->where('id', $order->id)->update(['commercial_snapshot' => json_encode($snapshot)]);
        $this->getJson($this->url())->assertOk();
        $row = DB::table('customer_receivables')->where('delivery_line_id', $batch['line_id'])->firstOrFail();

        return [...$batch, 'receivable_id' => $row->id];
    }

    private function payment(array $debt, string $amount = '20.00', string $mode = 'cash'): array
    {
        return ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'mode' => $mode,
            'allocations' => [['receivable_id' => $debt['receivable_id'], 'amount' => $amount]]];
    }

    public function test_partial_payment_replay_conflict_and_exact_balance(): void
    {
        $debt = $this->debt();
        $input = $this->payment($debt);
        $id = $this->postJson($this->url('payment'), $input)->assertCreated()->json('data.id');
        $this->postJson($this->url('payment'), $input)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.id', $id);
        $changed = $this->payment($debt, '21.00');
        $changed['request_key'] = $input['request_key'];
        $this->postJson($this->url('payment'), $changed)->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '30.00')->assertJsonPath('data.credit', '0.00')->assertJsonPath('data.receivables.0.status', 'partially_paid');
    }

    public function test_overpayment_invalid_money_duplicate_allocation_and_foreign_debt_are_refused(): void
    {
        $debt = $this->debt();
        $this->postJson($this->url('payment'), $this->payment($debt, '50.01'))->assertConflict();
        $input = $this->payment($debt, '0.00');
        $this->postJson($this->url('payment'), $input)->assertUnprocessable();
        $input = $this->payment($debt, '1e2');
        $this->postJson($this->url('payment'), $input)->assertUnprocessable();
        $input = $this->payment($debt);
        $input['allocations'][] = $input['allocations'][0];
        $this->postJson($this->url('payment'), $input)->assertUnprocessable();
        $input = $this->payment(['receivable_id' => (string) Str::uuid()]);
        $this->postJson($this->url('payment'), $input)->assertNotFound();
        $input = $this->payment($debt);
        $input['customer_id'] = 99;
        $this->postJson($this->url('payment'), $input)->assertUnprocessable();
    }

    public function test_cheque_receipt_is_pending_and_collection_is_distinct_and_capped(): void
    {
        $this->terms('postdated_cheque');
        $debt = $this->debt(mode: 'postdated_cheque');
        $input = [...$this->payment($debt, '30.00', 'postdated_cheque'), 'reference' => 'CHEQUE-PRIVATE', 'maturity_date' => '2026-11-12'];
        $id = $this->postJson($this->url('payment'), $input)->assertCreated()->json('data.id');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '50.00')->assertJsonPath('data.pending_cheques', '30.00');
        $collect = ['request_key' => (string) Str::uuid(), 'payment_id' => $id, 'reference' => 'Encaissement bancaire'];
        $this->postJson($this->url('collect'), $collect)->assertConflict();
        $this->terms('cash');
        $this->postJson($this->url('payment'), $this->payment($debt, '21.00'))->assertConflict();
        $this->postJson($this->url('payment'), $this->payment($debt, '20.00'))->assertCreated();
        $this->travel(1)->days();
        $this->postJson($this->url('collect'), $collect)->assertCreated();
        $this->postJson($this->url('collect'), $collect)->assertOk();
        $this->postJson($this->url('collect'), [...$collect, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '0.00')->assertJsonPath('data.pending_cheques', '0.00');
    }

    public function test_correction_is_justified_immutable_and_reopens_debt_once(): void
    {
        $debt = $this->debt();
        $id = $this->postJson($this->url('payment'), $this->payment($debt))->assertCreated()->json('data.id');
        $input = ['request_key' => (string) Str::uuid(), 'payment_id' => $id, 'reason' => 'Erreur de saisie'];
        $this->postJson($this->url('reverse'), [...$input, 'reason' => '  '])->assertUnprocessable();
        $this->postJson($this->url('reverse'), $input)->assertCreated();
        $this->postJson($this->url('reverse'), $input)->assertOk();
        $this->postJson($this->url('reverse'), [...$input, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '50.00');
        $this->assertDatabaseHas('customer_finance_operations', ['id' => $id, 'action' => 'payment']);
        $this->assertDatabaseHas('audit_events', ['action' => 'finance.reverse', 'actor_id' => $this->actor->id]);
    }

    public function test_credit_and_actual_refund_share_one_capped_right(): void
    {
        $debt = $this->debt();
        $right = DB::transaction(fn () => app(CustomerFinance::class)->registerCredit($this->customer->user_id, 'return:fixture-1', '40.00'));
        $this->assertSame($right, DB::transaction(fn () => app(CustomerFinance::class)->registerCredit($this->customer->user_id, 'return:fixture-1', '40.00')));
        $input = ['request_key' => (string) Str::uuid(), 'credit_id' => $right, 'amount' => '30.00', 'reason' => 'Affectation demandée', 'allocations' => [['receivable_id' => $debt['receivable_id'], 'amount' => '30.00']]];
        $this->postJson($this->url('credit_apply'), $input)->assertCreated();
        $this->postJson($this->url('credit_apply'), $input)->assertOk();
        $refund = ['request_key' => (string) Str::uuid(), 'credit_id' => $right, 'amount' => '10.01', 'reason' => 'Remboursement réel', 'reference' => 'Caisse-1'];
        $this->postJson($this->url('refund'), $refund)->assertConflict();
        $refund['amount'] = '10.00';
        $this->postJson($this->url('refund'), $refund)->assertCreated();
        $this->postJson($this->url('refund'), $refund)->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '20.00')->assertJsonPath('data.credit', '0.00')->assertJsonPath('data.refunds', '10.00');
    }

    public function test_settlement_does_not_clear_block_and_only_protected_admin_unblocks_with_reason(): void
    {
        $debt = $this->debt('2026-11-10');
        $this->postJson($this->url('payment'), $this->payment($debt, '50.00'))->assertCreated();
        $state = $this->getJson($this->url())->assertOk()->assertJsonPath('data.blocked', true)->assertJsonPath('data.debt', '0.00')->json('data');
        $input = ['request_key' => (string) Str::uuid(), 'version' => $state['version'], 'reason' => 'Régularisation vérifiée'];
        $this->postJson($this->url('unblock'), $input)->assertForbidden();
        $this->grant($this->actor, ['ROLE_MANAGE', 'USER_ASSIGN_ROLE']);
        $role = $this->actor->roles()->first();
        DB::table('roles')->where('id', $role->id)->update(['is_protected' => true]);
        $this->postJson($this->url('unblock'), $input)->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.blocked', false);
    }

    public function test_reminders_are_internal_once_and_scheduler_latches_overdue_blocks(): void
    {
        $debt = $this->debt('2026-11-13');
        $this->artisan('receivables:review')->assertSuccessful();
        $this->artisan('receivables:review')->assertSuccessful();
        $this->assertDatabaseCount('customer_finance_reminders', 1);
        $this->travel(3)->days();
        $this->artisan('receivables:review')->assertSuccessful();
        $this->assertDatabaseHas('customer_finance_accounts', ['customer_id' => $this->customer->user_id, 'blocked' => true]);
        $this->assertFalse(DB::transaction(fn () => app(CustomerFinance::class)->creditCheck($this->customer->user_id))['allowed']);
    }

    public function test_own_visibility_real_permissions_and_immediate_revocation(): void
    {
        $debt = $this->debt();
        $this->postJson($this->url('payment'), $this->payment($debt))->assertCreated();
        $this->get('/mes-soldes')->assertOk();
        $this->actingAs($this->customer->user);
        $this->getJson('/api/v1/me/finance')->assertOk()->assertJsonPath('data.debt', '30.00')->assertJsonMissingPath('data.operations')->assertJsonMissingPath('data.version');
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('payment'), $this->payment($debt))->assertForbidden();
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($other->user);
        $this->getJson('/api/v1/me/finance')->assertOk()->assertJsonPath('data.debt', '0.00');
        $this->actingAs($this->actor);
        $this->actor->roles()->detach();
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('payment'), $this->payment($debt))->assertForbidden();
    }

    public function test_missing_historical_date_is_explicit_and_blocks_payment_and_credit_check(): void
    {
        $this->delivery($this->actor, $this->customer);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.complete', false)->assertJsonPath('data.unresolved_delivery_count', 1);
        $this->assertFalse(DB::transaction(fn () => app(CustomerFinance::class)->creditCheck($this->customer->user_id))['allowed']);
        $this->artisan('receivables:review')->assertFailed();
    }

    public function test_changed_profile_terms_and_late_invoice_never_rewrite_or_duplicate_delivered_debt(): void
    {
        $debt = $this->debt('2026-11-20');
        $this->postJson($this->url('payment'), $this->payment($debt))->assertCreated();
        $this->enableTestBilling();
        $this->grant($this->actor, ['INVOICE_ISSUE']);
        $input = $this->invoiceInput($this->customer, [$this->selection($debt, 5)]);
        $before = $this->stockState();
        $this->postJson('/api/v1/billing/invoices', $input)->assertCreated();
        $this->postJson('/api/v1/billing/invoices', $input)->assertOk();
        $this->assertSame($before, $this->stockState());
        $this->terms('cheque');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '30.00')->assertJsonPath('data.receivables.0.due_date', '2026-11-20')->assertJsonPath('data.receivables.0.mode', 'cash');
        $this->assertDatabaseCount('customer_receivables', 1);
    }

    public function test_audit_failure_rolls_back_payment_and_disabled_policy_refuses_writes(): void
    {
        $debt = $this->debt();
        $this->mock(AuditWriter::class)->shouldReceive('record')->andThrow(new RuntimeException('audit unavailable'));
        $this->postJson($this->url('payment'), $this->payment($debt))->assertServerError();
        $this->assertDatabaseCount('customer_finance_operations', 1);
        config(['receivables.enabled' => false]);
        $this->postJson($this->url('payment'), $this->payment($debt))->assertStatus(503);
    }

    public function test_real_partial_departure_creates_only_delivered_debt_and_invoice_adds_none(): void
    {
        $this->grant($this->actor, ['ORDER_CONFIRM', 'DELIVERY_PREPARE', 'DELIVERY_DEPARTURE_CONFIRM', 'INVOICE_ISSUE']);
        $order = $this->realDeliveryOrder($this->actor, $this->customer, 5, '10.00');
        $row = DB::table('customer_orders')->find($order['order_id']);
        $snapshot = json_decode($row->commercial_snapshot, true);
        $snapshot['billing_v1']['payment_terms'] = ['mode' => 'cash', 'label' => 'Conditions historiques', 'due_date' => '2026-11-20'];
        DB::table('customer_orders')->where('id', $row->id)->update(['commercial_snapshot' => json_encode($snapshot)]);
        $batch = $this->dispatchQuantity($this->actor, $order, 3);
        $this->assertDatabaseCount('customer_receivables', 1);
        $this->assertDatabaseHas('customer_receivables', ['delivery_line_id' => $batch['line_id'], 'amount' => '30.00']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '30.00')->assertJsonPath('data.credit', '0.00')->assertJsonPath('data.refunds', '0.00');
        $this->enableTestBilling();
        $input = $this->invoiceInput($this->customer, [$this->selection($batch, 3)]);
        $this->postJson('/api/v1/billing/invoices', $input)->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.debt', '30.00');
        $reservation = DB::table('stock_reservations')->where('order_id', $order['order_id'])->firstOrFail();
        $this->assertSame(2, (int) $reservation->quantity - (int) $reservation->consumed);
        $this->assertDatabaseCount('customer_credit_rights', 0);
    }

    public function test_live_stock_reservation_enforces_overdue_credit_decision(): void
    {
        $this->debt('2026-11-10');
        $this->grant($this->actor, ['ORDER_CONFIRM']);
        try {
            $this->realDeliveryOrder($this->actor, $this->customer);
            $this->fail('An overdue account must not reserve new stock.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_finance_approval_requires_one_explicit_mode_and_preserves_existing_workflow(): void
    {
        $pending = CustomerProfile::factory()->create();
        $this->grant($this->actor, ['CUSTOMER_APPROVE']);
        $payload = ['decision' => 'APPROUVE', 'version' => 1, 'reason' => 'Identité vérifiée'];
        $url = '/api/v1/customers/'.$pending->id.'/approval-decisions';
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('payment_terms');
        $this->assertSame('EN_ATTENTE', $pending->fresh()->approval);
        $payload['payment_terms'] = ['mode' => 'cheque', 'label' => 'Chèque à la livraison'];
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('customer_payment_terms', ['customer_id' => $pending->user_id, 'mode' => 'cheque']);
        $this->assertDatabaseCount('approval_notifications', 1);
        $this->assertSame('APPROUVE', $pending->fresh()->approval);
    }

    public function test_financial_history_cannot_be_updated_or_deleted_in_database(): void
    {
        $this->debt();
        foreach (['customer_receivables', 'customer_payment_terms', 'customer_finance_operations'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    $id = DB::table($table)->value('id');
                    if ($action === 'update') {
                        DB::table($table)->where('id', $id)->update(['created_at' => now()->addDay()]);
                    } else {
                        DB::table($table)->where('id', $id)->delete();
                    }
                    $this->fail('Financial history must remain immutable.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('Financial history is immutable', $exception->getMessage());
                }
            }
        }
    }

    public function test_large_credit_preserves_every_cent_without_floating_point_rounding(): void
    {
        $right = DB::transaction(fn () => app(CustomerFinance::class)->registerCredit($this->customer->user_id, 'return:large-fixture', '9999999999999999999999.99'));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.credit', '9999999999999999999999.99');
        $this->postJson($this->url('refund'), ['request_key' => (string) Str::uuid(), 'credit_id' => $right,
            'amount' => '0.01', 'reference' => 'Fixture cent', 'reason' => 'Exact arithmetic test'])->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.credit', '9999999999999999999999.98')->assertJsonPath('data.refunds', '0.01');
    }

    public function test_disabled_policy_does_not_import_debt_or_latch_blocks_when_viewing_balances(): void
    {
        $this->debt('2026-11-12');
        $this->travel(2)->days();
        config(['receivables.enabled' => false]);
        $extra = $this->delivery($this->actor, $this->customer);
        $this->confirmBilling($extra, $this->customer);
        $account = DB::table('customer_finance_accounts')->where('customer_id', $this->customer->user_id)->first();
        $auditCount = DB::table('audit_events')->where('action', 'like', 'finance.%')->count();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.blocked', false)->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.unresolved_delivery_count', 1)->assertJsonPath('data.debt', '50.00');
        $this->actingAs($this->customer->user);
        $this->getJson('/api/v1/me/finance')->assertOk()->assertJsonPath('data.blocked', false);
        $this->artisan('receivables:review')->assertSuccessful();
        app(CustomerFinance::class)->review($this->customer->user_id);
        $this->assertDatabaseCount('customer_receivables', 1);
        $this->assertDatabaseCount('customer_finance_reminders', 0);
        $this->assertSame((array) $account, (array) DB::table('customer_finance_accounts')->where('customer_id', $this->customer->user_id)->first());
        $this->assertSame($auditCount, DB::table('audit_events')->where('action', 'like', 'finance.%')->count());
        $this->assertSame(['allowed' => false, 'reason' => 'policy_disabled'], app(CustomerFinance::class)->creditCheck($this->customer->user_id));
    }

    public function test_disabled_policy_refuses_direct_debt_import_and_credit_creation(): void
    {
        config(['receivables.enabled' => false]);
        foreach (['synchronize', 'registerCredit'] as $method) {
            try {
                DB::transaction(fn () => $method === 'synchronize'
                    ? app(CustomerFinance::class)->synchronize($this->customer->user_id)
                    : app(CustomerFinance::class)->registerCredit($this->customer->user_id, 'return:disabled', '1.00'));
                $this->fail('Disabled policy must refuse financial source writes.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(503, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('customer_receivables', 0);
        $this->assertDatabaseCount('customer_credit_rights', 0);
    }
}
