<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerIdentityChange;
use App\Models\CustomerProfile;
use App\Models\NotificationDelivery;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationDeliverySender;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CustomerIdentityChangeTest extends TestCase
{
    use DatabaseMigrations;

    private CustomerProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'version' => 1]);
        $this->signIn($this->profile->user);
    }

    public function test_proposal_and_retry_leave_live_account_unchanged_and_queue_one_verification(): void
    {
        $payload = $this->payload('new-owner@example.test');
        $response = $this->postJson($this->ownUrl(), $payload)->assertCreated()->assertJsonPath('data.emailVerified', false);
        $this->postJson($this->ownUrl(), $payload)->assertOk();
        $this->postJson($this->ownUrl(), $this->payload())->assertConflict();
        $this->assertSame($this->profile->user->email, $this->profile->user->fresh()->email);
        $this->assertSame('PARTICULIER', $this->profile->fresh()->type);
        $this->assertDatabaseCount('customer_identity_changes', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertSame(0, Mail::mailer()->getSymfonyTransport()->messages()->count());
        $this->assertStringNotContainsString('verification_url', $response->getContent());
        $event = AuditEvent::where('action', 'customer.identity_change.requested')->sole();
        $this->assertStringNotContainsString('new-owner@example.test', $event->toJson());
        $this->assertStringNotContainsString('New Company', $event->toJson());
    }

    public function test_company_fields_and_protected_inputs_are_validated(): void
    {
        $payload = $this->payload();
        unset($payload['company_name']);
        $this->postJson($this->ownUrl(), $payload)->assertUnprocessable();
        $this->postJson($this->ownUrl(), [...$this->payload(), 'type' => 'PARTICULIER'])->assertUnprocessable();
        foreach (['user_id' => 1, 'approval' => 'APPROUVE', 'phone' => '00123', 'address' => 'Other',
            'email_verified_at' => now()->toISOString(), 'status' => 'APPROVED'] as $key => $value) {
            $this->postJson($this->ownUrl(), [...$this->payload(), $key => $value])->assertUnprocessable();
        }
        User::factory()->create(['email' => 'TAKEN@example.test']);
        $this->postJson($this->ownUrl(), $this->payload('taken@example.test'))->assertUnprocessable();
        $this->assertDatabaseCount('customer_identity_changes', 0);
    }

    public function test_verified_existing_email_allows_identity_review_without_new_email_delivery(): void
    {
        $change = $this->submit();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $fresh = $this->profile->fresh();
        $this->assertSame('ENTREPRISE', $fresh->type);
        $this->assertSame('New Company', $fresh->company_name);
        $this->assertSame('New Contact', $fresh->user->name);
        $this->assertSame($this->profile->user->email, $fresh->user->email);
        $this->assertSame($this->profile->phone, $fresh->phone);
        $this->assertSame($this->profile->address, $fresh->address);
        $this->assertSame('APPROUVE', $fresh->approval);
        $this->assertSame(2, $fresh->version);
    }

    public function test_changed_email_requires_mailbox_verification_and_staff_approval(): void
    {
        $change = $this->submit('new-owner@example.test');
        $delivery = NotificationDelivery::sole();
        $this->assertTrue(app(NotificationDeliverySender::class)->send($delivery->id));
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('new-owner@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString($delivery->payload['verification_url'], $message->getTextBody());
        $this->assertFalse(app(NotificationDeliverySender::class)->send($delivery->id));
        $this->reviewer();
        $oldDecision = $this->decision($change);
        $this->postJson($this->reviewUrl($change).'/decisions', $oldDecision)->assertConflict();
        $this->getJson($delivery->payload['verification_url'])->assertOk();
        $this->assertSame($this->profile->user->email, $this->profile->user->fresh()->email);
        $this->postJson($this->reviewUrl($change).'/decisions', $oldDecision)->assertConflict();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertOk();
        $this->assertSame('new-owner@example.test', $this->profile->user->fresh()->email);
        $this->assertNotNull($this->profile->user->fresh()->email_verified_at);
        $this->assertSame('APPROVED', $change->fresh()->status);
    }

    public function test_email_links_reject_tampering_and_resend_invalidates_old_revision(): void
    {
        $change = $this->submit('new-owner@example.test');
        $old = NotificationDelivery::sole();
        $url = $old->payload['verification_url'];
        $this->getJson($url.'&revision=999')->assertForbidden();
        $this->postJson($this->ownUrl().'/'.$change->id.'/verification', ['version' => 1])->assertStatus(202);
        $this->getJson($url)->assertConflict();
        $this->assertFalse(app(NotificationDeliverySender::class)->send($old->id));
        $this->assertSame('EXPIRED', $old->fresh()->status);
        $new = NotificationDelivery::whereKeyNot($old->id)->sole();
        $this->getJson($new->payload['verification_url'])->assertOk();
        $version = $change->fresh()->version;
        $this->getJson($new->payload['verification_url'])->assertOk();
        $this->assertSame($version, $change->fresh()->version);
    }

    public function test_expired_email_link_can_be_replaced_without_changing_the_live_email(): void
    {
        $this->freezeTime();
        $change = $this->submit('new-owner@example.test');
        $old = NotificationDelivery::sole();
        $this->travel(25)->hours();
        $this->getJson($old->payload['verification_url'])->assertForbidden();
        $this->postJson($this->ownUrl().'/'.$change->id.'/verification', ['version' => 1])->assertStatus(202);
        $new = NotificationDelivery::whereKeyNot($old->id)->sole();
        $this->getJson($new->payload['verification_url'])->assertOk();
        $this->assertSame($this->profile->user->email, $this->profile->user->fresh()->email);
    }

    public function test_switch_to_particulier_clears_company_fields_only_after_approval(): void
    {
        $this->profile->fill(['type' => 'ENTREPRISE', 'company_name' => 'Old Company', 'responsible_name' => 'Old Person'])->save();
        $payload = $this->payload();
        $payload['type'] = 'PARTICULIER';
        unset($payload['company_name'], $payload['responsible_name']);
        $this->postJson($this->ownUrl(), $payload)->assertCreated();
        $change = CustomerIdentityChange::sole();
        $this->assertSame('Old Company', $this->profile->fresh()->company_name);
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertOk();
        $this->assertNull($this->profile->fresh()->company_name);
        $this->assertNull($this->profile->fresh()->responsible_name);
        $this->assertSame('PARTICULIER', $this->profile->fresh()->type);
    }

    public function test_refusal_does_not_change_identity_and_stops_pending_verification_mail(): void
    {
        $change = $this->submit('new-owner@example.test');
        $delivery = NotificationDelivery::sole();
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', [...$this->decision($change), 'decision' => 'REFUSE'])->assertOk();
        $this->assertFalse(app(NotificationDeliverySender::class)->send($delivery->id));
        $this->assertSame('EXPIRED', $delivery->fresh()->status);
        $this->assertSame($this->profile->user->email, $this->profile->user->fresh()->email);
        $this->assertSame('PARTICULIER', $this->profile->fresh()->type);
        $this->signIn($this->profile->user);
        $response = $this->getJson($this->ownUrl())->assertOk();
        $this->assertStringNotContainsString('Private reason', $response->getContent());
    }

    public function test_email_taken_after_verification_conflicts_without_partial_identity_changes(): void
    {
        $change = $this->submit('new-owner@example.test');
        $this->getJson(NotificationDelivery::sole()->payload['verification_url'])->assertOk();
        User::factory()->create(['email' => 'NEW-OWNER@example.test']);
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertConflict();
        $this->assertSame($this->profile->user->name, $this->profile->user->fresh()->name);
        $this->assertSame('PARTICULIER', $this->profile->fresh()->type);
        $this->assertSame('PENDING', $change->fresh()->status);
    }

    public function test_customer_cannot_read_others_or_use_staff_review_and_revocation_is_rechecked(): void
    {
        $change = $this->submit();
        $this->getJson('/api/v1/customer-identity-changes')->assertForbidden();
        $this->getJson($this->ownUrl().'?user_id=1')->assertUnprocessable();
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'version' => 1]);
        $this->signIn($other->user);
        $this->getJson($this->ownUrl())->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->ownUrl().'/'.$change->id.'/verification', ['version' => 1])->assertNotFound();
        $reviewer = $this->reviewer();
        $this->getJson('/api/v1/customer-identity-changes')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->reviewUrl($change))->assertOk()->assertJsonPath('data.current_profile.version', 1);
        $reviewer->roles()->detach();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertForbidden();
    }

    public function test_phone_changes_require_a_fresh_review_and_are_preserved(): void
    {
        $change = $this->submit();
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1])->assertOk();
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', [...$this->decision($change), 'profile_version' => 1])->assertConflict();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertOk();
        $this->assertSame('0012345', $this->profile->fresh()->phone);
    }

    public function test_audit_failure_rolls_back_name_email_company_and_request_status(): void
    {
        $change = $this->submit('new-owner@example.test');
        $this->getJson(NotificationDelivery::sole()->payload['verification_url'])->assertOk();
        $this->reviewer();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'customer.identity_change.decided') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change));
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame($this->profile->user->email, $this->profile->user->fresh()->email);
        $this->assertSame($this->profile->user->name, $this->profile->user->fresh()->name);
        $this->assertSame('PARTICULIER', $this->profile->fresh()->type);
        $this->assertSame(1, $this->profile->fresh()->version);
        $this->assertSame('PENDING', $change->fresh()->status);
    }

    public function test_unapproved_or_temporary_accounts_and_guests_cannot_submit(): void
    {
        $this->profile->forceFill(['approval' => 'EN_ATTENTE'])->save();
        $this->postJson($this->ownUrl(), $this->payload())->assertForbidden();
        $this->profile->forceFill(['approval' => 'APPROUVE'])->save();
        $this->profile->user->forceFill(['must_change_password' => true])->save();
        $this->postJson($this->ownUrl(), $this->payload())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->ownUrl(), $this->payload())->assertUnauthorized();
    }

    public function test_email_delivery_intent_failure_rolls_back_submission(): void
    {
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'notification.requested') {
                throw new RuntimeException('Outbox audit unavailable');
            }
        });
        try {
            $this->postJson($this->ownUrl(), $this->payload('new-owner@example.test'));
            $this->fail('Expected outbox audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Outbox audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseCount('customer_identity_changes', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_self_review_is_refused_and_completed_decisions_cannot_be_repeated(): void
    {
        $change = $this->submit();
        $this->reviewer($this->profile->user);
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertForbidden();
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertOk();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertConflict();
        $this->assertSame(1, AuditEvent::where('action', 'customer.identity_change.decided')->count());
    }

    public function test_another_customer_cannot_reuse_the_original_request_uuid(): void
    {
        $payload = $this->payload('new-owner@example.test');
        $this->postJson($this->ownUrl(), $payload)->assertCreated();
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'version' => 1]);
        $this->signIn($other->user);
        $this->postJson($this->ownUrl(), $payload)->assertConflict();
        $this->assertDatabaseCount('customer_identity_changes', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_verification_transport_failure_is_retryable_without_changing_account(): void
    {
        $change = $this->submit('new-owner@example.test');
        $delivery = NotificationDelivery::sole();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Transport unavailable'));
        $this->assertFalse(app(NotificationDeliverySender::class)->send($delivery->id));
        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->next_attempt_at);
        $this->assertNull($change->fresh()->email_verified_at);
        $this->assertSame($this->profile->user->email, $this->profile->user->fresh()->email);
    }

    public function test_unchanged_unverified_email_is_not_falsely_marked_verified(): void
    {
        $user = $this->profile->user;
        $user->forceFill(['email_verified_at' => null])->save();
        $this->signIn($user);
        $response = $this->postJson($this->ownUrl(), $this->payload())->assertCreated()
            ->assertJsonPath('data.emailVerified', false)->assertJsonPath('data.emailVerificationRequired', false);
        $change = CustomerIdentityChange::findOrFail($response->json('data.id'));
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision($change))->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** @return array<string, mixed> */
    private function payload(?string $email = null): array
    {
        return ['request_id' => (string) Str::uuid(), 'version' => 1, 'name' => 'New Contact',
            'email' => $email ?? $this->profile->user->email, 'type' => 'ENTREPRISE',
            'company_name' => 'New Company', 'responsible_name' => 'Responsible Person'];
    }

    private function submit(?string $email = null): CustomerIdentityChange
    {
        $payload = $this->payload($email);
        $this->postJson($this->ownUrl(), $payload)->assertCreated();

        return CustomerIdentityChange::findOrFail($payload['request_id']);
    }

    /** @return array<string, mixed> */
    private function decision(CustomerIdentityChange $change): array
    {
        return ['decision' => 'APPROUVE', 'version' => $change->fresh()->version,
            'profile_version' => $this->profile->fresh()->version, 'reason' => 'Private reason'];
    }

    private function reviewer(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::firstOrCreate(['code' => 'CUSTOMER_APPROVE'], ['module' => 'access', 'label' => 'Approve customers']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        $this->signIn($user);

        return $user;
    }

    private function signIn(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function ownUrl(): string
    {
        return '/api/v1/me/profile/identity-change-requests';
    }

    private function reviewUrl(CustomerIdentityChange $change): string
    {
        return '/api/v1/customer-identity-changes/'.$change->id;
    }
}
