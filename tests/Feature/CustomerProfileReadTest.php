<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProfileReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_reads_only_their_profile_with_current_version_and_no_internal_fields(): void
    {
        $own = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'version' => 3, 'internal_notes' => 'private-staff-note']);
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($own->user, 'web');
        $response = $this->getJson('/api/v1/me/profile')->assertOk()
            ->assertJsonPath('data.customerId', $own->id)->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.phone', $own->phone);
        $this->assertStringNotContainsString($other->user->email, $response->getContent());
        $this->assertStringNotContainsString('private-staff-note', $response->getContent());
        $this->assertStringNotContainsString($own->user->password, $response->getContent());
        $this->assertArrayNotHasKey('roles', $response->json('data'));
        $this->assertArrayNotHasKey('decision_reason', $response->json('data'));
        $this->assertSame(3, $own->fresh()->version);
    }

    public function test_identity_filters_are_rejected_and_another_customer_id_is_not_a_route(): void
    {
        $own = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($own->user, 'web');
        $this->getJson('/api/v1/me/profile?customerId='.$other->id)->assertUnprocessable();
        $this->getJson('/api/v1/me/profile?user_id='.$other->user_id)->assertUnprocessable();
        $this->getJson('/api/v1/me/profile/'.$other->id)->assertNotFound();
    }

    public function test_guests_cannot_read_profile(): void
    {
        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
    }

    public function test_staff_without_customer_profile_cannot_read_another_account(): void
    {
        CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/me/profile')->assertNotFound();
    }

    public function test_pending_and_refused_customers_cannot_read_protected_profile(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'EN_ATTENTE']);
        $this->actingAs($profile->user, 'web');
        $this->getJson('/api/v1/me/profile')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_PENDING');
        $profile->forceFill(['approval' => 'REFUSE'])->save();
        $this->getJson('/api/v1/me/profile')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_REFUSED');
    }

    public function test_temporary_account_is_restricted_and_fresh_profile_version_is_returned(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user, 'web');
        $this->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('data.version', $profile->fresh()->version);
        $profile->forceFill(['version' => 4])->save();
        $this->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('data.version', 4);
        $profile->user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/me/profile')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }
}
