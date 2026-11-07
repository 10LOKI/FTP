<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class CustomerPhoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_update_preserves_other_fields_and_records_only_safe_version_metadata(): void
    {
        $profile = $this->customer();
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '+2120612345678', 'version' => 1])
            ->assertOk()->assertJsonPath('data.phone', '+2120612345678')->assertJsonPath('data.version', 2);
        $this->assertSame($profile->address, $profile->fresh()->address);
        $this->assertSame($profile->type, $profile->fresh()->type);
        $this->assertSame($other->phone, $other->fresh()->phone);
        $event = AuditEvent::where('action', 'customer.profile.phone_updated')->sole();
        $this->assertSame($profile->user_id, $event->actor_id);
        $this->assertStringNotContainsString('+2120612345678', $event->toJson());
        $this->assertSame(2, $event->changes['after']['version']);
    }

    public function test_stale_edits_are_refused_and_same_phone_is_a_no_op(): void
    {
        $profile = $this->customer();
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => $profile->phone, 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(0, AuditEvent::where('action', 'customer.profile.phone_updated')->count());
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1])->assertOk();
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0099999', 'version' => 1])->assertConflict();
        $this->assertSame('0012345', $profile->fresh()->phone);
        $this->assertSame(1, AuditEvent::where('action', 'customer.profile.phone_updated')->count());
    }

    public function test_address_identity_and_target_account_injection_are_rejected(): void
    {
        $profile = $this->customer();
        foreach (['address' => 'New address', 'city' => 'New city', 'country' => 'Other',
            'name' => 'Other', 'email' => 'other@example.test', 'type' => 'ENTREPRISE',
            'company_name' => 'Other', 'approval' => 'APPROUVE', 'user_id' => 123] as $field => $value) {
            $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($profile->phone, $profile->fresh()->phone);
        $this->assertSame(1, $profile->fresh()->version);
    }

    public function test_invalid_phone_and_version_are_rejected(): void
    {
        $this->customer();
        foreach ([[], ['phone' => '', 'version' => 1], ['phone' => str_repeat('1', 51), 'version' => 1],
            ['phone' => 1234, 'version' => 1], ['phone' => '0012345', 'version' => 0],
            ['phone' => '0012345']] as $payload) {
            $this->patchJson('/api/v1/me/profile/phone', $payload)->assertUnprocessable();
        }
    }

    public function test_guests_pending_and_temporary_accounts_are_denied(): void
    {
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1])->assertUnauthorized();
        $profile = $this->customer();
        $profile->forceFill(['approval' => 'EN_ATTENTE'])->save();
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1])->assertForbidden();
        $profile->forceFill(['approval' => 'APPROUVE'])->save();
        $profile->user->forceFill(['must_change_password' => true])->save();
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1])->assertForbidden();
        $this->assertSame($profile->phone, $profile->fresh()->phone);
    }

    public function test_staff_account_without_profile_cannot_edit_another_customer(): void
    {
        CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs(User::factory()->create(), 'web');
        $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1])->assertNotFound();
    }

    public function test_audit_failure_rolls_back_phone_and_version(): void
    {
        $profile = $this->customer();
        $this->withoutExceptionHandling();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'customer.profile.phone_updated') {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            $this->patchJson('/api/v1/me/profile/phone', ['phone' => '0012345', 'version' => 1]);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame($profile->phone, $profile->fresh()->phone);
        $this->assertSame(1, $profile->fresh()->version);
    }

    private function customer(): CustomerProfile
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'version' => 1]);
        $this->actingAs($profile->user, 'web');

        return $profile;
    }
}
