<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AccountInactivityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountInactivityPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('calendarCases')]
    public function test_calendar_months_use_casablanca_wall_time_and_clamp_month_end(string $start, string $expected): void
    {
        $baseline = CarbonImmutable::parse($start, 'Africa/Casablanca')->utc();
        $user = User::factory()->create(['last_login_at' => $baseline]);
        $deadline = app(AccountInactivityPolicy::class)->assess($user)['deadline'];
        $this->assertSame($expected, $deadline->setTimezone('Africa/Casablanca')->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{string, string}> */
    public static function calendarCases(): array
    {
        return [
            'regular months' => ['2026-11-06 12:00:00', '2027-03-06 12:00:00'],
            'non-leap February' => ['2026-10-31 12:00:00', '2027-02-28 12:00:00'],
            'leap February' => ['2023-10-31 12:00:00', '2024-02-29 12:00:00'],
        ];
    }

    public function test_deadline_is_inclusive_and_one_microsecond_before_is_not_due(): void
    {
        $user = User::factory()->create(['last_login_at' => CarbonImmutable::parse('2026-01-10 12:00:00', 'Africa/Casablanca')->utc()]);
        $policy = app(AccountInactivityPolicy::class);
        $deadline = $policy->assess($user)['deadline'];
        $this->assertSame('CURRENT', $policy->assess($user, $deadline->subMicrosecond())['status']);
        $this->assertSame('DUE', $policy->assess($user, $deadline)['status']);
    }

    public function test_never_logged_in_customer_uses_approval_date_not_registration(): void
    {
        $user = User::factory()->create(['created_at' => '2020-01-01 00:00:00']);
        $approval = CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC');
        CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE', 'decided_at' => $approval]);
        $assessment = app(AccountInactivityPolicy::class)->assess($user, $approval->addDay());
        $this->assertSame('approval', $assessment['source']);
        $this->assertSame('CURRENT', $assessment['status']);
        $this->assertSame('2027-02-01', $assessment['deadline']->setTimezone('Africa/Casablanca')->toDateString());
    }

    public function test_missing_customer_approval_is_reported_without_guessing_a_baseline(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'decided_at' => null]);
        $assessment = app(AccountInactivityPolicy::class)->assess($profile->user);
        $this->assertSame('MISSING_BASELINE', $assessment['status']);
        $this->assertNull($assessment['deadline']);
    }

    public function test_employee_without_login_uses_creation_and_customer_login_takes_precedence(): void
    {
        $created = CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC');
        $user = User::factory()->create(['created_at' => $created]);
        $policy = app(AccountInactivityPolicy::class);
        $this->assertSame('creation', $policy->assess($user)['source']);
        CustomerProfile::factory()->create(['user_id' => $user->id, 'approval' => 'APPROUVE', 'decided_at' => $created]);
        $user->forceFill(['last_login_at' => $created->addMonth()])->save();
        $this->assertSame('last_login', $policy->assess($user)['source']);
        $this->assertSame('2027-03-01', $policy->assess($user)['deadline']->setTimezone('Africa/Casablanca')->toDateString());
    }

    public function test_unapproved_customers_are_not_candidates_for_an_additional_inactivity_block(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'EN_ATTENTE']);
        $assessment = app(AccountInactivityPolicy::class)->assess($profile->user);
        $this->assertSame('NOT_APPROVED', $assessment['status']);
        $this->assertNull($assessment['deadline']);
    }

    public function test_report_is_read_only_and_identifies_missing_approval_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $user = User::factory()->create(['last_login_at' => '2026-01-01 00:00:00']);
        CustomerProfile::factory()->create(['approval' => 'APPROUVE', 'decided_at' => null]);
        $before = $user->fresh()->getAttributes();
        $this->artisan('accounts:inactivity-report')
            ->expectsOutputToContain('due: 1; missing baseline: 1. No accounts changed.')
            ->assertExitCode(0);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_events', 0);
    }
}
