<?php

namespace Tests\Feature;

use App\Models\AccountReactivationRequest;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class AccountReactivationTest extends TestCase
{
    use DatabaseMigrations;

    private CustomerProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:8090');
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $user = User::factory()->create(['password' => 'login-password', 'last_login_at' => '2026-01-01 12:00:00']);
        $this->profile = CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE', 'customer_code' => 'CLI-REACTIVATE']);
    }

    public function test_valid_inactive_login_opens_only_reactivation_access_without_advancing_activity(): void
    {
        $this->restrictedLogin();
        $user = $this->owner();
        $this->assertNotNull($user->inactivity_suspended_at);
        $this->assertSame('2026-01-01 12:00:00', $user->last_login_at->format('Y-m-d H:i:s'));
        $this->getJson('/api/v1/user')->assertForbidden()->assertJsonPath('code', 'INACTIVITY_SUSPENDED');
        $this->getJson('/api/v1/me/cart')->assertForbidden();
        $this->getJson('/api/v1/me/orders')->assertForbidden();
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1])->assertForbidden();
        $this->getJson($this->ownUrl())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_request_retries_are_idempotent_and_other_active_requests_are_refused(): void
    {
        $this->restrictedLogin();
        $payload = $this->payload();
        $this->postJson($this->ownUrl(), $payload)->assertCreated();
        $this->postJson($this->ownUrl(), $payload)->assertOk();
        $this->postJson($this->ownUrl(), $this->payload())->assertConflict();
        $this->postJson($this->ownUrl(), [...$payload, 'status' => 'APPROVED'])->assertUnprocessable();
        $this->getJson($this->ownUrl().'?user_id=1')->assertUnprocessable();
        $this->assertDatabaseCount('account_reactivation_requests', 1);
        $this->assertSame(1, AuditEvent::where('action', 'account.reactivation.requested')->count());
    }

    public function test_staff_approval_requires_fresh_login_before_reactivation(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $this->reviewer();
        $this->getJson('/api/v1/account-reactivations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->reviewUrl($reactivation))->assertOk();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertOk()
            ->assertJsonPath('data.requiresFreshLogin', true);
        $this->assertNotNull($this->owner()->inactivity_suspended_at);
        $this->assertSame('2026-01-01 12:00:00', $this->owner()->last_login_at->format('Y-m-d H:i:s'));
        $this->signIn($this->owner());
        $this->getJson('/api/v1/user')->assertForbidden();
        $this->getJson($this->ownUrl())->assertOk()->assertJsonPath('data.0.requiresFreshLogin', true);
        $this->freshGuest();
        $this->customerLogin()->assertOk();
        $this->assertNull($this->owner()->inactivity_suspended_at);
        $this->assertSame(now('UTC')->getTimestamp(), $this->owner()->last_login_at->getTimestamp());
        $this->assertSame('COMPLETED', $reactivation->fresh()->status);
        $this->assertNull($reactivation->fresh()->active_user_id);
        $this->getJson('/api/v1/user')->assertOk();
    }

    public function test_wrong_password_does_not_consume_an_approved_request(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $this->reviewer();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertOk();
        $this->freshGuest();
        $this->postJson('/api/v1/login', ['identifier' => 'CLI-REACTIVATE', 'password' => 'wrong-password'])->assertUnauthorized();
        $this->assertSame('APPROVED', $reactivation->fresh()->status);
        $this->assertNotNull($this->owner()->inactivity_suspended_at);
    }

    public function test_reactivation_approval_never_clears_customer_refusal(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $this->profile->forceFill(['approval' => 'REFUSE'])->save();
        $this->reviewer();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertOk();
        $this->freshGuest();
        $this->customerLogin()->assertForbidden()->assertJsonPath('code', 'ACCOUNT_REFUSED');
        $this->assertSame('REFUSE', $this->profile->fresh()->approval);
        $this->assertNotNull($this->owner()->inactivity_suspended_at);
        $this->assertSame('APPROVED', $reactivation->fresh()->status);
    }

    public function test_refusal_keeps_suspension_and_private_reasons_out_of_customer_response(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $this->reviewer();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', [...$this->decision(), 'decision' => 'REFUSE'])->assertOk();
        $this->assertNotNull($this->owner()->inactivity_suspended_at);
        $this->signIn($this->owner());
        $response = $this->getJson($this->ownUrl())->assertOk();
        $this->assertStringNotContainsString('Private decision reason', $response->getContent());
        $this->postJson($this->ownUrl(), $this->payload())->assertCreated();
    }

    public function test_stale_decisions_revoked_permissions_and_suspended_reviewers_are_denied(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $reviewer = $this->reviewer();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', [...$this->decision(), 'version' => 2])->assertConflict();
        $reviewer->roles()->detach();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertForbidden();
        $suspended = $this->reviewer();
        $suspended->forceFill(['inactivity_suspended_at' => now('UTC'), 'inactivity_version' => 1])->save();
        $this->signIn($suspended->fresh());
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertForbidden();
        $this->assertSame('PENDING', $reactivation->fresh()->status);
    }

    public function test_a_completed_approval_cannot_be_reused_for_the_next_inactivity_cycle(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $this->reviewer();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertOk();
        $this->freshGuest();
        $this->customerLogin()->assertOk();
        $this->travel(5)->months();
        $this->freshGuest();
        $this->customerLogin()->assertForbidden()->assertJsonPath('code', 'INACTIVITY_SUSPENDED');
        $this->assertSame(2, $this->owner()->inactivity_version);
        $this->assertSame('COMPLETED', $reactivation->fresh()->status);
        $this->postJson($this->ownUrl(), $this->payload())->assertCreated();
    }

    public function test_pre_suspension_and_restricted_sessions_cannot_upgrade_after_another_login(): void
    {
        $user = $this->owner();
        $user->forceFill(['inactivity_version' => 1, 'last_login_at' => now('UTC')])->save();
        $this->signIn($user);
        $this->withSession(['inactivity_version' => 0, 'inactivity_restricted' => false]);
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->signIn($user->fresh());
        $this->withSession(['inactivity_version' => 1, 'inactivity_restricted' => true]);
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_employee_flow_uses_email_but_still_requires_fresh_login_after_approval(): void
    {
        $employee = User::factory()->create(['password' => 'employee-password', 'access_version' => 1, 'last_login_at' => '2026-01-01 12:00:00']);
        $this->postJson('/api/v1/employee-login', ['email' => $employee->email, 'password' => 'employee-password'])
            ->assertForbidden()->assertJsonPath('code', 'INACTIVITY_SUSPENDED');
        $this->rememberCookie();
        $reactivation = $this->requestReactivation();
        $this->reviewer();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertOk();
        $this->freshGuest();
        $this->postJson('/api/v1/employee-login', ['email' => $employee->email, 'password' => 'employee-password'])->assertOk();
        $this->rememberCookie();
        $this->assertNull($employee->fresh()->inactivity_suspended_at);
        $this->assertSame('COMPLETED', $reactivation->fresh()->status);
        $this->getJson('/api/v1/user')->assertOk();
    }

    public function test_temporary_password_requirement_is_preserved_and_allows_only_safe_password_replacement(): void
    {
        $employee = User::factory()->create(['password' => 'temporary-password', 'access_version' => 1,
            'must_change_password' => true, 'temporary_password_expires_at' => now('UTC')->addDay(), 'last_login_at' => '2026-01-01 12:00:00']);
        $this->postJson('/api/v1/employee-login', ['email' => $employee->email, 'password' => 'temporary-password'])
            ->assertForbidden()->assertJsonPath('data.mustChangePassword', true);
        $this->rememberCookie();
        $this->postJson($this->ownUrl(), $this->payload())->assertForbidden();
        $this->postJson('/api/v1/temporary-password', ['current_password' => 'temporary-password', 'password' => 'new-permanent-password', 'password_confirmation' => 'new-permanent-password'])->assertOk();
        $this->assertNotNull($employee->fresh()->inactivity_suspended_at);
        $this->assertFalse($employee->fresh()->must_change_password);
        $this->assertDatabaseCount('account_reactivation_requests', 0);
    }

    public function test_request_audit_failure_rolls_back_active_slot(): void
    {
        $this->restrictedLogin();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'account.reactivation.requested') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->postJson($this->ownUrl(), $this->payload());
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseCount('account_reactivation_requests', 0);
    }

    public function test_fresh_login_audit_failure_restores_approved_request_and_suspension(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $this->reviewer();
        $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision())->assertOk();
        $this->freshGuest();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'customer.login' && $event->result === 'SUCCESS') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->customerLogin();
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertNotNull($this->owner()->inactivity_suspended_at);
        $this->assertSame('APPROVED', $reactivation->fresh()->status);
        $this->assertSame('2026-01-01 12:00:00', $this->owner()->last_login_at->format('Y-m-d H:i:s'));
        $this->assertGuest('web');
    }

    public function test_staff_decision_audit_failure_preserves_pending_request_and_suspension(): void
    {
        $this->restrictedLogin();
        $reactivation = $this->requestReactivation();
        $this->reviewer();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'account.reactivation.decided') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->postJson($this->reviewUrl($reactivation).'/decisions', $this->decision());
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame('PENDING', $reactivation->fresh()->status);
        $this->assertSame(1, $reactivation->fresh()->version);
        $this->assertNotNull($this->owner()->inactivity_suspended_at);
    }

    public function test_active_accounts_and_guests_cannot_submit_and_logout_remains_available(): void
    {
        $this->postJson($this->ownUrl(), $this->payload())->assertUnauthorized();
        $active = User::factory()->create();
        $this->signIn($active);
        $this->postJson($this->ownUrl(), $this->payload())->assertConflict();
        $this->freshGuest();
        $this->restrictedLogin();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    private function owner(): User
    {
        return User::findOrFail($this->profile->user_id);
    }

    private function customerLogin(): TestResponse
    {
        $response = $this->postJson('/api/v1/login', ['identifier' => 'CLI-REACTIVATE', 'password' => 'login-password']);
        $this->rememberCookie();

        return $response;
    }

    private function restrictedLogin(): void
    {
        $this->customerLogin()->assertForbidden()->assertJsonPath('code', 'INACTIVITY_SUSPENDED');
        $this->assertAuthenticatedAs($this->owner(), 'web');
    }

    private function rememberCookie(): void
    {
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    private function signIn(User $user): void
    {
        $this->freshGuest();
        $this->actingAs($user, 'web');
        $this->withSession(['employee_access_version' => $user->access_version,
            'inactivity_version' => $user->inactivity_version, 'inactivity_restricted' => $user->inactivity_suspended_at !== null]);
        $this->rememberCookie();
    }

    private function freshGuest(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function reviewer(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::firstOrCreate(['code' => 'CUSTOMER_APPROVE'], ['module' => 'access', 'label' => 'Review customers']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        $this->signIn($user);

        return $user;
    }

    private function requestReactivation(): AccountReactivationRequest
    {
        $response = $this->postJson($this->ownUrl(), $this->payload())->assertCreated();

        return AccountReactivationRequest::findOrFail($response->json('data.id'));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['request_id' => (string) Str::uuid(), 'reason' => 'Please review my inactivity suspension.'];
    }

    /** @return array<string, mixed> */
    private function decision(): array
    {
        return ['decision' => 'APPROUVE', 'version' => 1, 'reason' => 'Private decision reason'];
    }

    private function ownUrl(): string
    {
        return '/api/v1/me/reactivation-requests';
    }

    private function reviewUrl(AccountReactivationRequest $reactivation): string
    {
        return '/api/v1/account-reactivations/'.$reactivation->id;
    }
}
