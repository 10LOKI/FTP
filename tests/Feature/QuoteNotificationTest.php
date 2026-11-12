<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\QuoteNotificationSender;
use App\Services\SalesQuotes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class QuoteNotificationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['quotes.notifications_enabled' => true, 'quotes.issuance_enabled' => true, 'quotes.policy_version' => 'TEST-ONLY',
            'quotes.number_prefix' => 'DEV-TEST-', 'quotes.seller' => ['name' => 'Vendeur fictif', 'address' => 'Adresse fictive'],
            'quotes.tax_note' => 'Conditions fictives de test']);
    }

    /** @return array{int, User, CustomerProfile, array<string, mixed>} */
    private function scenario(): array
    {
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach (['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]));
        }
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        $id = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(),
            'status' => 'EN_VERIFICATION', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}',
            'lines' => '[]', 'catalogue_subtotal' => '200.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_order_reviews')->insert(['order_id' => $id, 'claimed_by' => $actor->id, 'claimed_at' => now('UTC')]);
        $draftId = (string) Str::uuid();
        $snapshot = ['schema_version' => 1, 'status' => 'DRAFT', 'customer' => ['company_name' => 'Client fictif'],
            'contact' => ['name' => 'Test', 'email' => 'untrusted-contact@example.test'], 'billing_address' => ['address' => 'Rabat'],
            'payment_terms_status' => 'ready', 'payment_terms' => ['mode' => 'test', 'label' => 'Conditions fictives'],
            'fees' => '0.00', 'currency' => 'MAD', 'tax_basis' => 'TTC', 'valid_until' => now('UTC')->addWeek()->toDateString(),
            'estimated_subtotal' => '200.00', 'lines' => [['sku' => 'TEST-1', 'designation' => 'Test', 'original_reference' => 'TEST',
                'quantity' => 2, 'units_per_package' => 1, 'unit_price' => '100.00', 'line_subtotal' => '200.00']]];
        DB::table('sales_quote_drafts')->insert(['id' => $draftId, 'order_id' => $id, 'actor_id' => $actor->id, 'revision' => 1, 'order_version' => 1,
            'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'snapshot' => json_encode($snapshot), 'created_at' => now('UTC')]);
        $record = DB::table('sales_quote_drafts')->where('id', $draftId)->first();
        $input = ['draft_id' => $draftId, 'expected_version' => 1, 'request_key' => (string) Str::uuid(),
            'issuance_token' => app(SalesQuotes::class)->readiness($record)['token']];

        return [$id, $actor, $customer, $input];
    }

    /** @return array{int, User, CustomerProfile, string, object} */
    private function issued(): array
    {
        [$order, $actor, $customer, $input] = $this->scenario();
        $quote = app(SalesQuotes::class)->issue($actor, $order, $input)['quote_id'];

        return [$order, $actor, $customer, $quote, DB::table('sales_quote_notifications')->where('quote_id', $quote)->first()];
    }

    private function transport(): ArrayTransport
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    public function test_committed_notification_intent_is_unique_on_issuance_replay_and_disabled_delivery_is_preserved(): void
    {
        [$order, $actor, $customer, $input] = $this->scenario();
        $service = app(SalesQuotes::class);
        $quote = $service->issue($actor, $order, $input)['quote_id'];
        $service->issue($actor, $order, $input);
        $this->assertDatabaseCount('sales_quote_notifications', 1);
        $intent = DB::table('sales_quote_notifications')->sole();
        $this->assertSame($quote, $intent->quote_id);
        $this->assertSame($customer->user_id, (int) $intent->recipient_id);
        $this->assertSame('sales.quote_issued', AuditEvent::findOrFail($intent->trigger_event_id)->action);
        config(['quotes.notifications_enabled' => false]);
        $this->assertFalse(app(QuoteNotificationSender::class)->send($intent->id));
        $this->artisan('quotes:deliver-notifications')->assertSuccessful();
        $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'PENDING', 'attempts' => 0]);
        $this->assertCount(0, $this->transport()->messages());
    }

    public function test_intent_failure_rolls_back_quote_and_issuance_audit_and_order_state(): void
    {
        [$order, $actor, , $input] = $this->scenario();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'sales_quote_notifications')) {
                throw new RuntimeException('Simulated outbox write failure');
            }
        });
        try {
            app(SalesQuotes::class)->issue($actor, $order, $input);
            $this->fail('Issuance should roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('sales_quotes', 0);
            $this->assertDatabaseCount('sales_quote_notifications', 0);
            $this->assertDatabaseHas('customer_orders', ['id' => $order, 'status' => 'EN_VERIFICATION', 'version' => 1]);
            $this->assertSame(0, AuditEvent::where('action', 'sales.quote_issued')->count());
        }
    }

    public function test_delivery_uses_customer_account_and_private_link_and_exposes_only_safe_staff_status(): void
    {
        [$order, $actor, $customer, $quote, $intent] = $this->issued();
        $sender = app(QuoteNotificationSender::class);
        $this->assertTrue($sender->send($intent->id));
        $this->assertFalse($sender->send($intent->id));
        $message = $this->transport()->messages()->sole()->getOriginalMessage();
        $this->assertSame($customer->user->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString(route('customer.orders.page'), $message->getTextBody());
        $this->assertStringContainsString('DEV-TEST-', $message->getTextBody());
        $this->assertStringContainsString('quote-notification.'.$intent->id.'@', $message->getHeaders()->get('Message-ID')->getBodyAsString());
        $this->assertStringNotContainsString('untrusted-contact', $message->toString());
        $this->assertStringNotContainsString($customer->user->password, $message->toString());
        $this->assertStringNotContainsString('payment_terms', $message->toString());
        $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'SENT', 'attempts' => 1]);
        $this->actingAs($actor)->getJson("/api/v1/sales/orders/$order")->assertOk()->assertJsonPath('data.quotes.0.notification.status', 'SENT')
            ->assertJsonMissingPath('data.quotes.0.notification.recipient_id')->assertJsonMissingPath('data.quotes.0.notification.trigger_event_id');
        $this->actingAs($customer->user)->getJson("/api/v1/me/orders/$order")->assertOk()->assertJsonMissingPath('data.quotes.0.notification');
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_failed_transport_is_retried_after_backoff_without_reissuing_quote(): void
    {
        [, , , , $intent] = $this->issued();
        Event::listen(MessageSending::class, fn (): bool => false);
        $sender = app(QuoteNotificationSender::class);
        $this->assertFalse($sender->send($intent->id));
        $this->assertFalse($sender->send($intent->id));
        $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'RETRY', 'attempts' => 1, 'last_error_code' => 'DELIVERY_FAILED']);
        Event::forget(MessageSending::class);
        $this->travel((int) config('notifications.retry_minutes'))->minutes();
        $this->artisan('quotes:deliver-notifications', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'SENT', 'attempts' => 2]);
        $this->assertCount(1, $this->transport()->messages());
        $this->assertDatabaseCount('sales_quotes', 1);
    }

    public function test_expired_modified_and_decided_offers_are_not_sent(): void
    {
        $sender = app(QuoteNotificationSender::class);
        foreach (['expired', 'modified', 'decided'] as $scenario) {
            [$order, $actor, , $quote, $intent] = $this->issued();
            if ($scenario === 'expired') {
                $this->travel(8)->days();
            } elseif ($scenario === 'modified') {
                DB::table('customer_orders')->where('id', $order)->increment('version');
            } else {
                app(SalesQuotes::class)->decide($actor, $order, $quote, ['expected_version' => 2, 'request_key' => (string) Str::uuid(),
                    'outcome' => 'REFUSED', 'contact_method' => 'PHONE', 'contact_name' => 'Test', 'contacted_at' => now('UTC')->format('Y-m-d H:i:s')]);
            }
            $this->assertFalse($sender->send($intent->id));
            $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'EXPIRED', 'attempts' => 0, 'last_error_code' => 'OFFER_NOT_CURRENT']);
            $this->travelBack();
        }
        $this->assertCount(0, $this->transport()->messages());
    }

    public function test_unavailable_account_is_not_sent_and_can_retry_after_approval_is_restored(): void
    {
        [, , $customer, , $intent] = $this->issued();
        $customer->forceFill(['approval' => 'REFUSE'])->save();
        $sender = app(QuoteNotificationSender::class);
        $this->assertFalse($sender->send($intent->id));
        $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'RETRY', 'last_error_code' => 'ACCOUNT_UNAVAILABLE']);
        $this->assertCount(0, $this->transport()->messages());
        $customer->forceFill(['approval' => 'APPROUVE'])->save();
        $this->travel((int) config('notifications.retry_minutes'))->minutes();
        $this->assertTrue($sender->send($intent->id));
        $this->assertCount(1, $this->transport()->messages());
    }

    public function test_delivery_rejects_open_transactions_and_logging_transports(): void
    {
        [, , , , $intent] = $this->issued();
        DB::beginTransaction();
        try {
            app(QuoteNotificationSender::class)->send($intent->id);
            $this->fail('Uncommitted delivery must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'attempts' => 0]);
        } finally {
            DB::rollBack();
        }
        $transport = $this->transport();
        config(['mail.default' => 'log']);
        $this->assertFalse(app(QuoteNotificationSender::class)->send($intent->id));
        $this->assertDatabaseHas('sales_quote_notifications', ['id' => $intent->id, 'status' => 'RETRY', 'last_error_code' => 'DELIVERY_FAILED']);
        $this->assertCount(0, $transport->messages());
    }

    public function test_command_rejects_invalid_batch_limits(): void
    {
        $this->artisan('quotes:deliver-notifications', ['--limit' => 0])->assertExitCode(2);
        $this->artisan('quotes:deliver-notifications', ['--limit' => 1001])->assertExitCode(2);
    }
}
