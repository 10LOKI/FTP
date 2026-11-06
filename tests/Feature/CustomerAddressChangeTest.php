<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerAddressChange;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CustomerAddressChangeTest extends TestCase
{
    use RefreshDatabase;

    private CustomerProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'version' => 1]);
        $this->signIn($this->profile->user);
    }

    public function test_submission_preserves_address_and_identical_retry_returns_same_request(): void
    {
        $payload = $this->payload();
        $first = $this->postJson($this->ownUrl(), $payload)->assertCreated()->assertJsonPath('data.status', 'PENDING');
        $this->postJson($this->ownUrl(), $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame($this->profile->address, $this->profile->fresh()->address);
        $this->assertSame(1, $this->profile->fresh()->version);
        $this->assertDatabaseCount('customer_address_changes', 1);
        $event = AuditEvent::where('action', 'customer.address_change.requested')->sole();
        $this->assertStringNotContainsString($payload['address'], $event->toJson());
        $payload['address'] = 'Different proposal';
        $this->postJson($this->ownUrl(), $payload)->assertConflict();
    }

    public function test_only_one_pending_request_is_allowed_and_stale_submission_is_refused(): void
    {
        $this->submit();
        $this->postJson($this->ownUrl(), $this->payload())->assertConflict();
        $this->assertDatabaseCount('customer_address_changes', 1);
        $this->profile->forceFill(['version' => 2])->save();
        $this->postJson($this->ownUrl(), $this->payload())->assertConflict();
    }

    public function test_submission_rejects_identity_target_and_status_injection(): void
    {
        foreach (['user_id' => 1, 'customer_profile_id' => $this->profile->id, 'phone' => '00123',
            'status' => 'APPROVED', 'type' => 'ENTREPRISE', 'name' => 'Other', 'email' => 'other@example.test'] as $key => $value) {
            $this->postJson($this->ownUrl(), [...$this->payload(), $key => $value])->assertUnprocessable();
        }
        $this->postJson($this->ownUrl(), [...$this->payload(), 'address' => ''])->assertUnprocessable();
        $this->postJson($this->ownUrl(), [...$this->payload(), 'request_id' => 'bad'])->assertUnprocessable();
        $this->assertDatabaseCount('customer_address_changes', 0);
    }

    public function test_customer_tracking_is_own_only_and_rejects_identity_filters(): void
    {
        $change = $this->submit();
        CustomerAddressChange::factory()->create();
        $this->getJson($this->ownUrl())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $change->id);
        $this->getJson($this->ownUrl().'?customer_profile_id='.$this->profile->id)->assertUnprocessable();
        $this->getJson('/api/v1/customer-address-changes')->assertForbidden();
        $this->getJson($this->reviewUrl($change))->assertForbidden();
    }

    public function test_staff_queue_and_detail_use_real_customer_approval_permission(): void
    {
        $change = $this->submit();
        $this->reviewer();
        $this->getJson('/api/v1/customer-address-changes?per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->reviewUrl($change))->assertOk()
            ->assertJsonPath('data.current_profile.version', 1)
            ->assertJsonPath('data.change.address', 'Requested street 42');
        $this->getJson('/api/v1/customer-address-changes?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/customer-address-changes?status=APPROVED')->assertUnprocessable();
    }

    public function test_approval_updates_only_address_fields_and_closes_pending_slot(): void
    {
        $payload = $this->payload();
        $this->postJson($this->ownUrl(), $payload)->assertCreated();
        $change = CustomerAddressChange::findOrFail($payload['request_id']);
        $reviewer = $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision())->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.version', 2);
        $fresh = $this->profile->fresh();
        $this->assertSame('Requested street 42', $fresh->address);
        $this->assertSame($this->profile->phone, $fresh->phone);
        $this->assertSame($this->profile->type, $fresh->type);
        $this->assertSame('APPROUVE', $fresh->approval);
        $this->assertSame(2, $fresh->version);
        $this->assertNull($change->fresh()->active_customer_profile_id);
        $this->assertSame($reviewer->id, $change->fresh()->reviewed_by);
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision())->assertConflict();
        $this->signIn($this->profile->user);
        $this->postJson($this->ownUrl(), $payload)->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertSame(1, AuditEvent::where('action', 'customer.address_change.decided')->count());
    }

    public function test_refusal_leaves_profile_unchanged_and_keeps_reason_private(): void
    {
        $change = $this->submit();
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision('REFUSE'))->assertOk()
            ->assertJsonPath('data.status', 'REFUSED');
        $this->assertSame($this->profile->address, $this->profile->fresh()->address);
        $this->assertSame(1, $this->profile->fresh()->version);
        $this->signIn($this->profile->user);
        $response = $this->getJson($this->ownUrl())->assertOk();
        $this->assertStringNotContainsString('Private review reason', $response->getContent());
        $this->assertStringNotContainsString('Private review reason', AuditEvent::where('action', 'customer.address_change.decided')->sole()->toJson());
        $this->postJson($this->ownUrl(), $this->payload())->assertCreated();
    }

    public function test_stale_review_is_rejected_but_refreshed_review_preserves_a_phone_change(): void
    {
        $change = $this->submit();
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '00123456', 'version' => 1])->assertOk();
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision())->assertConflict();
        $this->getJson($this->reviewUrl($change))->assertOk()->assertJsonPath('data.current_profile.version', 2);
        $this->postJson($this->reviewUrl($change).'/decisions', [...$this->decision(), 'profile_version' => 2])->assertOk();
        $this->assertSame('00123456', $this->profile->fresh()->phone);
        $this->assertSame(3, $this->profile->fresh()->version);
    }

    public function test_revoked_permission_or_self_review_cannot_decide(): void
    {
        $change = $this->submit();
        $this->grant($this->profile->user);
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision())->assertForbidden();
        $reviewer = $this->reviewer();
        $reviewer->roles()->detach();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision())->assertForbidden();
        $this->assertSame('PENDING', $change->fresh()->status);
    }

    public function test_invalid_decision_fields_and_versions_are_refused(): void
    {
        $change = $this->submit();
        $this->reviewer();
        foreach ([['reason' => ''], ['decision' => 'OTHER'], ['profile_version' => 0], ['user_id' => 1]] as $overrides) {
            $this->postJson($this->reviewUrl($change).'/decisions', [...$this->decision(), ...$overrides])->assertUnprocessable();
        }
        $this->postJson($this->reviewUrl($change).'/decisions', [...$this->decision(), 'version' => 2])->assertConflict();
    }

    public function test_customer_no_longer_approved_cannot_have_address_applied(): void
    {
        $change = $this->submit();
        $this->profile->forceFill(['approval' => 'REFUSE'])->save();
        $this->reviewer();
        $this->postJson($this->reviewUrl($change).'/decisions', $this->decision())->assertConflict();
        $this->assertSame('REFUSE', $this->profile->fresh()->approval);
        $this->assertSame($this->profile->address, $this->profile->fresh()->address);
    }

    public function test_guests_pending_and_temporary_customers_cannot_submit(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson($this->ownUrl(), $this->payload())->assertUnauthorized();
        $this->signIn($this->profile->user);
        $this->profile->forceFill(['approval' => 'EN_ATTENTE'])->save();
        $this->postJson($this->ownUrl(), $this->payload())->assertForbidden();
        $this->profile->forceFill(['approval' => 'APPROUVE'])->save();
        $this->profile->user->forceFill(['must_change_password' => true])->save();
        $this->postJson($this->ownUrl(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('customer_address_changes', 0);
    }

    public function test_audit_failure_rolls_back_decision_address_and_pending_slot(): void
    {
        $change = $this->submit();
        $this->reviewer();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'customer.address_change.decided') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->postJson($this->reviewUrl($change).'/decisions', $this->decision());
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame('PENDING', $change->fresh()->status);
        $this->assertSame($this->profile->id, $change->fresh()->active_customer_profile_id);
        $this->assertSame($this->profile->address, $this->profile->fresh()->address);
        $this->assertSame(1, $this->profile->fresh()->version);
    }

    public function test_another_customer_cannot_replay_an_existing_request_id(): void
    {
        $payload = $this->payload();
        $this->postJson($this->ownUrl(), $payload)->assertCreated();
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'version' => 1]);
        $this->signIn($other->user);
        $this->postJson($this->ownUrl(), $payload)->assertConflict();
        $this->getJson($this->ownUrl())->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('customer_address_changes', 1);
    }

    public function test_audit_failure_rolls_back_submission_and_allows_a_clean_retry(): void
    {
        $payload = $this->payload();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'customer.address_change.requested') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->postJson($this->ownUrl(), $payload);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseCount('customer_address_changes', 0);
        $this->postJson($this->ownUrl(), $payload)->assertCreated();
    }

    public function test_pending_slot_is_also_protected_by_a_database_unique_constraint(): void
    {
        CustomerAddressChange::factory()->create(['customer_profile_id' => $this->profile->id, 'active_customer_profile_id' => $this->profile->id]);
        $this->expectException(QueryException::class);
        CustomerAddressChange::factory()->create(['customer_profile_id' => $this->profile->id, 'active_customer_profile_id' => $this->profile->id]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['request_id' => (string) Str::uuid(), 'version' => 1,
            'address' => 'Requested street 42', 'city' => 'Rabat', 'country' => 'Maroc'];
    }

    /** @return array<string, mixed> */
    private function decision(string $decision = 'APPROUVE'): array
    {
        return ['decision' => $decision, 'version' => 1, 'profile_version' => 1, 'reason' => 'Private review reason'];
    }

    private function submit(): CustomerAddressChange
    {
        $payload = $this->payload();
        $this->postJson($this->ownUrl(), $payload)->assertCreated();

        return CustomerAddressChange::findOrFail($payload['request_id']);
    }

    private function reviewer(): User
    {
        $user = User::factory()->create();
        $this->grant($user);
        $this->signIn($user);

        return $user;
    }

    private function grant(User $user): void
    {
        $role = Role::factory()->create();
        $permission = Permission::firstOrCreate(['code' => 'CUSTOMER_APPROVE'], ['module' => 'access', 'label' => 'Approve customers']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['assigned_by' => $user->id, 'assigned_at' => now()]);
    }

    private function signIn(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function ownUrl(): string
    {
        return '/api/v1/me/profile/address-change-requests';
    }

    private function reviewUrl(CustomerAddressChange $change): string
    {
        return '/api/v1/customer-address-changes/'.$change->id;
    }
}
