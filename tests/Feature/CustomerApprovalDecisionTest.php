<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\IssuedCustomerCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CustomerApprovalDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_account_without_permission_cannot_decide(): void
    {
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), $this->payload())->assertUnauthorized();
        $this->actingAs($profile->user, 'web');
        $this->postJson($this->url($profile), $this->payload())->assertForbidden();
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->assertDatabaseCount('issued_customer_codes', 0);
    }

    public function test_approval_records_actor_time_reason_code_and_safe_audit(): void
    {
        $reviewer = $this->signInReviewer();
        $profile = CustomerProfile::factory()->create(['internal_notes' => 'Private staff note']);
        $passwordHash = $profile->user->password;
        $this->travelTo(now()->startOfSecond());

        $this->postJson($this->url($profile), $this->payload())->assertOk()
            ->assertExactJson(['data' => [
                'customerId' => $profile->id,
                'approval' => 'APPROUVE',
                'customerCode' => 'CLI-000001',
                'version' => 2,
                'decidedBy' => $reviewer->id,
                'decidedAt' => now('UTC')->toISOString(),
                'reason' => $this->payload()['reason'],
            ]]);

        $profile->refresh();
        $this->assertSame('APPROUVE', $profile->approval);
        $this->assertSame($reviewer->id, $profile->decided_by);
        $this->assertSame($this->payload()['reason'], $profile->decision_reason);
        $this->assertSame($passwordHash, $profile->user->password);
        $this->assertDatabaseHas('issued_customer_codes', ['code' => 'CLI-000001', 'customer_profile_id' => $profile->id]);
        $event = AuditEvent::where('action', 'customer.approval_decision')->sole();
        $this->assertSame($reviewer->id, $event->actor_id);
        $this->assertSame('SUCCESS', $event->result);
        $this->assertSame('EN_ATTENTE', $event->changes['before']['approval']);
        $this->assertSame('APPROUVE', $event->changes['after']['approval']);
        $this->assertStringNotContainsString($passwordHash, $event->toJson());
        $this->assertStringNotContainsString('Private staff note', $event->toJson());
        $this->assertStringNotContainsString($this->payload()['reason'], $event->toJson());
    }

    public function test_refusal_records_decision_without_issuing_a_code(): void
    {
        $reviewer = $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), $this->payload('REFUSE'))->assertOk()
            ->assertJsonPath('data.approval', 'REFUSE')->assertJsonPath('data.customerCode', null);

        $this->assertSame($reviewer->id, $profile->fresh()->decided_by);
        $this->assertDatabaseCount('issued_customer_codes', 0);
        $this->assertSame(1, DB::table('customer_code_sequences')->value('next_number'));
        $this->assertSame('refusal_recorded', AuditEvent::where('action', 'customer.approval_decision')->sole()->reason);
    }

    public function test_supplied_existing_code_preserves_zeros_and_punctuation(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), $this->payload() + ['customer_code' => '000-ABC/12'])->assertOk()
            ->assertJsonPath('data.customerCode', '000-ABC/12');
        $this->assertSame('000-ABC/12', IssuedCustomerCode::sole()->code);
    }

    public function test_preassigned_legacy_code_is_preserved(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create(['customer_code' => '000-LEGACY']);
        $this->postJson($this->url($profile), $this->payload())->assertOk()
            ->assertJsonPath('data.customerCode', '000-LEGACY');
    }

    public function test_preassigned_code_cannot_be_replaced(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create(['customer_code' => '000-LEGACY']);
        $this->postJson($this->url($profile), $this->payload() + ['customer_code' => 'NEW'])->assertConflict();
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
    }

    public function test_automatic_sequence_skips_an_imported_code(): void
    {
        $this->signInReviewer();
        $first = CustomerProfile::factory()->create();
        $second = CustomerProfile::factory()->create();
        $this->postJson($this->url($first), $this->payload() + ['customer_code' => 'CLI-000001'])->assertOk();
        $this->postJson($this->url($second), $this->payload())->assertOk()
            ->assertJsonPath('data.customerCode', 'CLI-000002');
    }

    public function test_sequence_grows_beyond_six_digits_without_wrapping(): void
    {
        $this->signInReviewer();
        DB::table('customer_code_sequences')->where('name', 'customer')->update(['next_number' => 999999]);
        $first = CustomerProfile::factory()->create();
        $second = CustomerProfile::factory()->create();
        $this->postJson($this->url($first), $this->payload())->assertOk()->assertJsonPath('data.customerCode', 'CLI-999999');
        $this->postJson($this->url($second), $this->payload())->assertOk()->assertJsonPath('data.customerCode', 'CLI-1000000');
    }

    public function test_a_registered_code_cannot_be_reissued_even_if_profile_code_is_cleared(): void
    {
        $this->signInReviewer();
        $first = CustomerProfile::factory()->create();
        $this->postJson($this->url($first), $this->payload() + ['customer_code' => '000-ABC'])->assertOk();
        DB::table('customer_profiles')->where('id', $first->id)->update(['customer_code' => null]);
        $second = CustomerProfile::factory()->create();
        $this->postJson($this->url($second), $this->payload() + ['customer_code' => '000-ABC'])->assertConflict();
        $this->assertSame('EN_ATTENTE', $second->fresh()->approval);
        $this->assertDatabaseCount('issued_customer_codes', 1);
    }

    public function test_two_customers_cannot_be_assigned_the_same_code(): void
    {
        $this->signInReviewer();
        $first = CustomerProfile::factory()->create();
        $second = CustomerProfile::factory()->create();
        $this->postJson($this->url($first), $this->payload() + ['customer_code' => 'EXISTING-001'])->assertOk();
        $this->postJson($this->url($second), $this->payload() + ['customer_code' => 'EXISTING-001'])->assertConflict();
        $this->assertSame('EN_ATTENTE', $second->fresh()->approval);
    }

    public function test_repeated_decision_does_not_allocate_again_or_overwrite_history(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), $this->payload())->assertOk();
        $this->postJson($this->url($profile), $this->payload('REFUSE'))->assertConflict();
        $this->assertSame('APPROUVE', $profile->fresh()->approval);
        $this->assertDatabaseCount('issued_customer_codes', 1);
        $this->assertSame(2, DB::table('customer_code_sequences')->value('next_number'));
        $this->assertSame(1, AuditEvent::where('action', 'customer.approval_decision')->where('result', 'SUCCESS')->count());
        $this->assertSame('decision_conflict', AuditEvent::where('action', 'customer.approval_decision')->where('result', 'REFUSED')->sole()->reason);
    }

    public function test_outdated_version_is_rejected_without_changes(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create(['version' => 2]);
        $this->postJson($this->url($profile), $this->payload())->assertConflict();
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->assertDatabaseCount('issued_customer_codes', 0);
    }

    public function test_invalid_or_injected_decision_fields_are_rejected(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), $this->payload() + ['decided_by' => 999])->assertUnprocessable()
            ->assertJsonValidationErrors('decided_by');
        $this->postJson($this->url($profile), ['decision' => 'REFUSE', 'version' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->assertDatabaseCount('issued_customer_codes', 0);
    }

    public function test_success_audit_failure_rolls_back_the_entire_decision_and_counter(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'customer.approval_decision' && $event->result === 'SUCCESS') {
                throw new RuntimeException('Simulated decision audit failure');
            }
        });
        $this->postJson($this->url($profile), $this->payload())->assertStatus(500);

        $this->assertSame('EN_ATTENTE', $profile->fresh()->approval);
        $this->assertNull($profile->fresh()->decided_by);
        $this->assertDatabaseCount('issued_customer_codes', 0);
        $this->assertSame(1, DB::table('customer_code_sequences')->value('next_number'));
        $this->assertSame('FAILED', AuditEvent::where('action', 'customer.approval_decision')->sole()->result);
    }

    public function test_missing_customer_returns_not_found(): void
    {
        $this->signInReviewer();
        $this->postJson('/api/v1/customers/00000000-0000-0000-0000-000000000001/approval-decisions', $this->payload())->assertNotFound();
    }

    public function test_issued_code_cannot_be_deleted_through_the_model(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create();
        $this->postJson($this->url($profile), $this->payload())->assertOk();
        $this->expectException(LogicException::class);
        IssuedCustomerCode::sole()->delete();
    }

    private function signInReviewer(): User
    {
        $reviewer = User::factory()->create();
        $this->actingAs($reviewer, 'web');
        Gate::define('CUSTOMER_APPROVE', fn (User $user): bool => $user->id === $reviewer->id);

        return $reviewer;
    }

    private function url(CustomerProfile $profile): string
    {
        return '/api/v1/customers/'.$profile->id.'/approval-decisions';
    }

    /** @return array<string, mixed> */
    private function payload(string $decision = 'APPROUVE'): array
    {
        return ['decision' => $decision, 'version' => 1, 'reason' => 'Customer information reviewed'];
    }
}
