<?php

namespace Tests\Feature;

use App\Models\ApprovalNotification;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\EmployeeAccessDelivery;
use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalNotificationSender;
use App\Services\CartService;
use App\Services\CustomerOrderService;
use App\Services\EmployeeAccessSender;
use App\Services\NotificationDeliverySender;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DurableNotificationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => 'UTC']);
    }

    public function test_order_notifications_remain_disabled_until_the_business_approves_the_channel(): void
    {
        config(['notifications.order_received_enabled' => false]);
        [$user, $payload] = $this->cart();
        app(CustomerOrderService::class)->submit($user->id, $payload, (string) Str::uuid());
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertSame(0, $this->transport()->messages()->count());
    }

    public function test_committed_intent_is_unique_on_replay_and_contains_only_approved_fields(): void
    {
        config(['notifications.order_received_enabled' => true]);
        [$user, $payload] = $this->cart();
        $order = app(CustomerOrderService::class)->submit($user->id, $payload, (string) Str::uuid())['order'];
        app(CustomerOrderService::class)->submit($user->id, $payload, (string) Str::uuid());
        $delivery = NotificationDelivery::sole();
        $this->assertSame(['order_id' => (string) $order->id, 'order_number' => $order->number], $delivery->payload);
        $this->assertSame('PENDING', $delivery->status);
        $this->assertSame(0, $this->transport()->messages()->count());
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertSame('order.submitted', AuditEvent::findOrFail($delivery->trigger_event_id)->action);
    }

    public function test_outbox_failure_rolls_back_order_cart_consumption_and_success_audit(): void
    {
        config(['notifications.order_received_enabled' => true]);
        [$user, $payload] = $this->cart();
        $this->actingAs($user);
        NotificationDelivery::creating(function (): void {
            throw new RuntimeException('SECRET-outbox');
        });
        $this->postJson('/api/v1/me/orders', $payload)->assertStatus(500);
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertSame(0, AuditEvent::where('action', 'order.submitted')->count());
        $this->assertSame(1, AuditEvent::where('action', 'order.submit')->where('result', 'FAILED')->count());
    }

    public function test_failed_mail_preserves_order_and_persistent_retry_sends_without_another_order(): void
    {
        config(['notifications.order_received_enabled' => true]);
        [$user, $payload] = $this->cart();
        app(CustomerOrderService::class)->submit($user->id, $payload, (string) Str::uuid());
        $delivery = NotificationDelivery::sole();
        $this->freezeTime();
        Event::listen(MessageSending::class, fn (): bool => false);
        $sender = app(NotificationDeliverySender::class);
        $this->assertFalse($sender->send($delivery->id));
        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertFalse($sender->send($delivery->id));
        Event::forget(MessageSending::class);
        $this->travel(5)->minutes();
        $this->assertTrue($sender->send($delivery->id));
        $this->assertFalse($sender->send($delivery->id));
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseCount('cart_lines', 0);
        $this->assertSame(1, AuditEvent::where('action', 'order.submitted')->count());
        $message = $this->transport()->messages()->sole()->getOriginalMessage();
        $this->assertSame($user->email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('CMD-', $message->getTextBody());
        $this->assertStringNotContainsString('INTERNAL-MARKER', $message->toString());
        $this->assertStringNotContainsString($user->password, $message->toString());
        $this->assertSame(1, AuditEvent::where('action', 'notification.delivery')->where('result', 'SUCCESS')->count());
    }

    public function test_delivery_is_not_allowed_inside_an_uncommitted_transaction(): void
    {
        $delivery = NotificationDelivery::factory()->create();
        DB::beginTransaction();
        try {
            app(NotificationDeliverySender::class)->send($delivery->id);
            $this->fail('Cannot deliver uncommitted intent.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('committed', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $this->transport()->messages()->count());
    }

    public function test_log_transport_cannot_receive_notification_message_content(): void
    {
        config(['notifications.order_received_enabled' => true, 'mail.default' => 'log']);
        $delivery = NotificationDelivery::factory()->create();
        $this->assertFalse(app(NotificationDeliverySender::class)->send($delivery->id));
        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->assertSame('DELIVERY_FAILED', $delivery->fresh()->last_error_code);
    }

    public function test_notification_list_excludes_recipients_payloads_and_all_secrets(): void
    {
        $user = User::factory()->create();
        $delivery = NotificationDelivery::factory()->create(['recipient_id' => $user->id,
            'payload' => ['order_number' => 'SECRET-PAYLOAD', 'order_id' => '1']]);
        $this->getJson('/api/v1/notification-deliveries')->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/v1/notification-deliveries')->assertForbidden();
        Gate::define('AUDIT_READ', fn (): bool => true);
        $response = $this->getJson('/api/v1/notification-deliveries')->assertOk()->assertJsonPath('data.0.id', $delivery->id);
        $this->assertStringNotContainsString('SECRET-PAYLOAD', $response->getContent());
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertArrayNotHasKey('recipient_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('payload', $response->json('data.0'));
    }

    public function test_retry_is_permission_guarded_checks_attempt_and_never_sends_in_the_request(): void
    {
        config(['notifications.order_received_enabled' => true]);
        $delivery = NotificationDelivery::factory()->create();
        $delivery->status = 'RETRY';
        $delivery->attempts = 2;
        $delivery->next_attempt_at = now()->addDay();
        $delivery->save();
        $url = '/api/v1/notification-deliveries/order/'.$delivery->id.'/retry';
        $this->actingAs(User::factory()->create());
        $this->postJson($url, ['expected_attempts' => 2])->assertForbidden();
        Gate::define('NOTIFICATION_RETRY', fn (): bool => true);
        $this->postJson($url, ['expected_attempts' => 1])->assertConflict();
        $this->postJson($url, ['expected_attempts' => 2, 'recipient_id' => 42])->assertUnprocessable();
        $this->postJson($url, ['expected_attempts' => 2])->assertAccepted();
        $this->assertTrue($delivery->fresh()->next_attempt_at->lessThanOrEqualTo(now()));
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(0, $this->transport()->messages()->count());
        $this->assertSame(1, AuditEvent::where('action', 'notification.retry_requested')->where('result', 'SUCCESS')->count());
        $this->assertSame('notification_delivery', AuditEvent::where('action', 'notification.retry_requested')->where('result', 'SUCCESS')->sole()->object_type);
        $delivery->status = 'SENT';
        $delivery->save();
        $this->postJson($url, ['expected_attempts' => 2])->assertConflict();
    }

    public function test_retry_cannot_activate_unapproved_order_notifications(): void
    {
        config(['notifications.order_received_enabled' => false]);
        $delivery = NotificationDelivery::factory()->create();
        $delivery->status = 'RETRY';
        $delivery->save();
        $this->actingAs(User::factory()->create());
        Gate::define('NOTIFICATION_RETRY', fn (): bool => true);
        $this->postJson('/api/v1/notification-deliveries/order/'.$delivery->id.'/retry', ['expected_attempts' => 0])->assertConflict();
        $this->assertFalse(app(NotificationDeliverySender::class)->send($delivery->id));
        $this->assertSame(0, $delivery->fresh()->attempts);
    }

    public function test_retry_requires_csrf_in_a_real_stateful_session(): void
    {
        $delivery = NotificationDelivery::factory()->create();
        $this->actingAs(User::factory()->create());
        Gate::define('NOTIFICATION_RETRY', fn (): bool => true);
        $this->app->instance('env', 'local');
        try {
            $this->postJson('/api/v1/notification-deliveries/order/'.$delivery->id.'/retry', ['expected_attempts' => 0])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_worker_processes_only_due_intents_and_respects_its_limit(): void
    {
        config(['notifications.order_received_enabled' => true]);
        $due = NotificationDelivery::factory()->create();
        $future = NotificationDelivery::factory()->create();
        $future->status = 'RETRY';
        $future->next_attempt_at = now()->addDay();
        $future->save();
        $this->artisan('notifications:deliver', ['--limit' => '1'])->assertSuccessful();
        $this->assertSame('SENT', $due->fresh()->status);
        $this->assertSame(0, $future->fresh()->attempts);
        $this->assertSame(1, $this->transport()->messages()->count());
    }

    public static function invalidLimits(): array
    {
        return ['zero' => ['0'], 'too many' => ['1001'], 'not a number' => ['abc']];
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_worker_limit_sends_nothing(string $limit): void
    {
        $delivery = NotificationDelivery::factory()->create();
        $this->artisan('notifications:deliver', ['--limit' => $limit])->assertExitCode(2);
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertSame(0, $this->transport()->messages()->count());
    }

    public function test_delivery_audit_failure_keeps_order_and_intent_without_repeating_business_effect(): void
    {
        config(['notifications.order_received_enabled' => true]);
        [$user, $payload] = $this->cart();
        app(CustomerOrderService::class)->submit($user->id, $payload, (string) Str::uuid());
        $delivery = NotificationDelivery::sole();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'notification.delivery') {
                throw new RuntimeException('Simulated delivery audit outage');
            }
        });
        try {
            app(NotificationDeliverySender::class)->send($delivery->id);
            $this->fail('Delivery completion requires its audit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated delivery audit outage', $exception->getMessage());
        }
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertSame('PENDING', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertSame(1, AuditEvent::where('action', 'order.submitted')->count());
    }

    public function test_approval_delivery_retry_uses_the_real_outbox_without_reapproving_the_customer(): void
    {
        config(['mail.customer_portal_url' => 'https://portal.example.test']);
        $this->actingAs(User::factory()->create());
        Gate::define('CUSTOMER_APPROVE', fn (): bool => true);
        $profile = CustomerProfile::factory()->create();
        $this->postJson('/api/v1/customers/'.$profile->id.'/approval-decisions',
            ['decision' => 'APPROUVE', 'version' => 1, 'reason' => 'Fixture review'])->assertOk();
        $delivery = ApprovalNotification::sole();
        Event::listen(MessageSending::class, fn (): bool => false);
        $this->assertFalse(app(ApprovalNotificationSender::class)->send($delivery->id));
        Gate::define('NOTIFICATION_RETRY', fn (): bool => true);
        $this->postJson('/api/v1/notification-deliveries/approval/'.$delivery->id.'/retry', ['expected_attempts' => 1])->assertAccepted();
        Event::forget(MessageSending::class);
        $this->artisan('customers:send-approval-notifications')->assertSuccessful();
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertSame('APPROUVE', $profile->fresh()->approval);
        $this->assertSame(2, $profile->fresh()->version);
        $this->assertDatabaseCount('issued_customer_codes', 1);
        $this->assertDatabaseCount('approval_notifications', 1);
        $this->assertSame(1, AuditEvent::where('action', 'customer.approval_decision')->where('result', 'SUCCESS')->count());
    }

    public function test_employee_delivery_retry_requires_reset_permission_and_does_not_create_another_employee(): void
    {
        config(['employee_access.validity_hours' => 24, 'employee_access.portal_url' => 'https://portal.example.test']);
        $this->actingAs(User::factory()->create());
        Gate::define('USER_CREATE', fn (): bool => true);
        Gate::define('USER_ASSIGN_ROLE', fn (): bool => true);
        $role = Role::factory()->create();
        $this->postJson('/api/v1/users', ['email' => 'employee-fixture@example.test', 'role_ids' => [$role->id]])->assertCreated();
        $delivery = EmployeeAccessDelivery::sole();
        Event::listen(MessageSending::class, fn (): bool => false);
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        $version = User::findOrFail($delivery->user_id)->access_version;
        Gate::define('NOTIFICATION_RETRY', fn (): bool => true);
        $url = '/api/v1/notification-deliveries/employee/'.$delivery->id.'/retry';
        $this->postJson($url, ['expected_attempts' => 1])->assertForbidden();
        Gate::define('USER_RESET_ACCESS', fn (): bool => true);
        $this->postJson($url, ['expected_attempts' => 1])->assertAccepted();
        $this->assertSame($version, User::findOrFail($delivery->user_id)->access_version);
        $this->assertSame('employee_access_delivery', AuditEvent::where('action', 'notification.retry_requested')->where('result', 'SUCCESS')->sole()->object_type);
        $this->assertSame('employee_access_delivery', AuditEvent::where('action', 'notification.retry_requested')->where('result', 'REFUSED')->sole()->object_type);
        Event::forget(MessageSending::class);
        $this->artisan('employees:send-access')->assertSuccessful();
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('employee_access_deliveries', 1);
        $this->assertSame(1, AuditEvent::where('action', 'employee.provisioned')->count());
    }

    /** @return array{User, array<string, mixed>} */
    private function cart(): array
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'internal_notes' => 'INTERNAL-MARKER']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '10.00']);
        $cart = app(CartService::class)->putLine($profile->user_id, $product->id, 1, 1, 0, (string) Str::uuid());
        $address = ['address' => 'Adresse de test', 'city' => 'Rabat', 'country' => 'Maroc'];

        return [$profile->user, ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $cart['version'],
            'pricing_token' => CartService::pricingToken($cart), 'contact' => ['name' => 'Client', 'email' => 'client@example.test', 'phone' => '000'],
            'delivery_address' => $address, 'billing_address' => $address]];
    }

    private function transport(): ArrayTransport
    {
        return Mail::mailer('array')->getSymfonyTransport();
    }
}
