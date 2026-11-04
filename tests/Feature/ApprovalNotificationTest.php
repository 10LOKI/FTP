<?php

namespace Tests\Feature;

use App\Models\ApprovalNotification;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\ApprovalNotificationSender;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ApprovalNotificationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'mail.customer_portal_url' => 'https://portal.example.com']);
    }

    public function test_approval_saves_one_durable_intent_without_sending_in_the_request(): void
    {
        $notification = $this->approveCustomer();
        $this->assertSame('PENDING', $notification->status);
        $this->assertSame(0, $notification->attempts);
        $this->assertSame('CLI-000001', $notification->customer_code);
        $this->assertSame('https://portal.example.com', $notification->portal_url);
        $this->assertSame(0, $this->transport()->messages()->count());
        $this->assertDatabaseCount('approval_notifications', 1);
        $this->assertSame(1, AuditEvent::where('action', 'notification.requested')->count());
        $this->assertSame('customer.approval_decision', AuditEvent::findOrFail($notification->approval_event_id)->action);
    }

    public function test_refusal_does_not_create_an_approval_notification(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), ['decision' => 'REFUSE', 'version' => 1, 'reason' => 'Details could not be approved'])
            ->assertOk();
        $this->assertDatabaseCount('approval_notifications', 0);
        $this->assertSame(0, $this->transport()->messages()->count());
    }

    public function test_repeated_approval_cannot_duplicate_the_intent(): void
    {
        $notification = $this->approveCustomer();
        $profile = CustomerProfile::findOrFail($notification->customer_profile_id);
        $this->postJson($this->url($profile), $this->payload())->assertConflict();
        $this->assertDatabaseCount('approval_notifications', 1);
        $this->assertSame(1, AuditEvent::where('action', 'notification.requested')->count());
    }

    public function test_delivery_contains_code_and_portal_but_no_password_hash_or_private_notes(): void
    {
        $notification = $this->approveCustomer();
        $profile = CustomerProfile::findOrFail($notification->customer_profile_id);
        $user = $profile->user;
        $profile->internal_notes = 'Private review marker';
        $profile->save();
        $this->assertTrue(app(ApprovalNotificationSender::class)->send($notification->id));

        $message = $this->transport()->messages()->sole()->getOriginalMessage();
        $this->assertSame($notification->recipient_email, $message->getTo()[0]->getAddress());
        $this->assertStringContainsString($notification->customer_code, $message->getTextBody());
        $this->assertStringContainsString('https://portal.example.com', $message->getTextBody());
        $this->assertStringNotContainsString('private-customer-password', $message->toString());
        $this->assertStringNotContainsString($user->password, $message->toString());
        $this->assertStringNotContainsString('Private review marker', $message->toString());
        $this->assertNull($message->getHtmlBody());
        $this->assertSame('SENT', $notification->fresh()->status);
        $this->assertSame(1, $notification->fresh()->attempts);
        $this->assertNotNull($notification->fresh()->sent_at);
        $event = AuditEvent::where('action', 'notification.delivery')->sole();
        $this->assertNull($event->actor_id);
        $this->assertSame('mail_transport_accepted', $event->reason);
        $this->assertStringNotContainsString($notification->recipient_email, $event->toJson());
        $this->assertStringNotContainsString($user->password, $event->toJson());
    }

    public function test_already_sent_notification_is_not_sent_again(): void
    {
        $notification = $this->approveCustomer();
        $sender = app(ApprovalNotificationSender::class);
        $this->assertTrue($sender->send($notification->id));
        $this->assertFalse($sender->send($notification->id));
        $this->assertSame(1, $this->transport()->messages()->count());
        $this->assertSame(1, $notification->fresh()->attempts);
    }

    public function test_transport_failure_is_retryable_without_reapproving_or_reallocating(): void
    {
        $notification = $this->approveCustomer();
        $manager = Mail::getFacadeRoot();
        $pending = Mockery::mock();
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('smtp-secret-marker'));
        Mail::shouldReceive('to')->once()->with($notification->recipient_email)->andReturn($pending);
        $sender = app(ApprovalNotificationSender::class);
        $this->assertFalse($sender->send($notification->id));
        Mail::swap($manager);

        $notification->refresh();
        $this->assertSame('RETRY', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame('MAIL_TRANSPORT_FAILED', $notification->last_error_code);
        $this->assertNull($notification->sent_at);
        $this->assertStringNotContainsString('smtp-secret-marker', AuditEvent::where('action', 'notification.delivery')->sole()->toJson());
        $this->assertFalse($sender->send($notification->id));
        $this->assertSame(0, $this->transport()->messages()->count());

        $this->travel(5)->minutes();
        $this->assertTrue($sender->send($notification->id));
        $this->assertSame('SENT', $notification->fresh()->status);
        $this->assertSame(2, $notification->fresh()->attempts);
        $this->assertNull($notification->fresh()->last_error_code);
        $this->assertSame(1, $this->transport()->messages()->count());
        $this->assertSame('APPROUVE', CustomerProfile::findOrFail($notification->customer_profile_id)->approval);
        $this->assertSame(2, CustomerProfile::findOrFail($notification->customer_profile_id)->version);
        $this->assertDatabaseCount('issued_customer_codes', 1);
        $this->assertSame(2, DB::table('customer_code_sequences')->value('next_number'));
    }

    public function test_cancelled_mail_is_not_marked_sent(): void
    {
        $notification = $this->approveCustomer();
        Event::listen(MessageSending::class, fn () => false);
        $this->assertFalse(app(ApprovalNotificationSender::class)->send($notification->id));
        $this->assertSame('RETRY', $notification->fresh()->status);
        $this->assertSame(0, $this->transport()->messages()->count());
    }

    public function test_intent_storage_failure_rolls_back_approval_code_and_success_audit(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        ApprovalNotification::creating(function (): void {
            throw new RuntimeException('Simulated outbox failure');
        });
        $this->postJson($this->url($profile), $this->payload())->assertStatus(500);
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->assertDatabaseCount('approval_notifications', 0);
        $this->assertDatabaseCount('issued_customer_codes', 0);
        $this->assertSame(0, AuditEvent::where('result', 'SUCCESS')->count());
        $this->assertSame(1, DB::table('customer_code_sequences')->value('next_number'));
    }

    public function test_invalid_portal_configuration_cannot_commit_an_approval(): void
    {
        config(['mail.customer_portal_url' => 'javascript:invalid']);
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), $this->payload())->assertStatus(500);
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->assertDatabaseCount('approval_notifications', 0);
        $this->assertDatabaseCount('issued_customer_codes', 0);
    }

    public function test_sender_cannot_run_inside_the_approval_transaction(): void
    {
        $notification = $this->approveCustomer();
        $this->expectException(LogicException::class);
        DB::beginTransaction();

        try {
            app(ApprovalNotificationSender::class)->send($notification->id);
        } finally {
            DB::rollBack();
            $this->assertSame(0, $this->transport()->messages()->count());
        }
    }

    public function test_delivery_audit_failure_keeps_the_intent_retryable(): void
    {
        $notification = $this->approveCustomer();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'notification.delivery') {
                throw new RuntimeException('Simulated delivery audit failure');
            }
        });

        try {
            app(ApprovalNotificationSender::class)->send($notification->id);
            $this->fail('Expected an audit storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated delivery audit failure', $exception->getMessage());
        }

        $this->assertSame('PENDING', $notification->fresh()->status);
        $this->assertSame(1, $this->transport()->messages()->count());
        $this->assertSame('APPROUVE', CustomerProfile::findOrFail($notification->customer_profile_id)->approval);
    }

    public function test_processing_command_respects_batch_limit_and_skips_sent_rows(): void
    {
        $this->approveCustomer();
        $this->approveCustomer();
        $this->artisan('customers:send-approval-notifications --limit=1')->assertSuccessful();
        $this->assertSame(1, ApprovalNotification::where('status', 'SENT')->count());
        $this->artisan('customers:send-approval-notifications')->assertSuccessful();
        $this->artisan('customers:send-approval-notifications')->assertSuccessful();
        $this->assertSame(2, ApprovalNotification::where('status', 'SENT')->count());
        $this->assertSame(2, $this->transport()->messages()->count());
    }

    public function test_processing_command_rejects_invalid_batch_limits(): void
    {
        $this->artisan('customers:send-approval-notifications --limit=0')->assertExitCode(2);
        $this->artisan('customers:send-approval-notifications --limit=1001')->assertExitCode(2);
    }

    private function approveCustomer(): ApprovalNotification
    {
        $this->signInReviewer();
        $user = User::factory()->create(['password' => 'private-customer-password']);
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $this->postJson($this->url($profile), $this->payload())->assertOk();

        return ApprovalNotification::where('customer_profile_id', $profile->id)->sole();
    }

    private function signInReviewer(): void
    {
        $reviewer = auth('web')->user() ?? User::factory()->create();
        $this->actingAs($reviewer, 'web');
        Gate::define('CUSTOMER_APPROVE', fn (User $user): bool => $user->id === $reviewer->id);
    }

    private function transport(): ArrayTransport
    {
        return Mail::mailer()->getSymfonyTransport();
    }

    private function url(CustomerProfile $profile): string
    {
        return '/api/v1/customers/'.$profile->id.'/approval-decisions';
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['decision' => 'APPROUVE', 'version' => 1, 'reason' => 'Customer details reviewed'];
    }
}
