<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\EmployeeAccessDelivery;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeAccessSender;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EmployeeProvisioningTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'employee_access.validity_hours' => 24, 'employee_access.portal_url' => 'https://staff.example.com']);
    }

    public function test_creation_is_atomic_private_and_does_not_send_mail_in_request(): void
    {
        $delivery = $this->provision();
        $user = User::findOrFail($delivery->user_id);
        $this->assertTrue($user->must_change_password);
        $this->assertSame(1, $user->access_version);
        $this->assertSame(1, $user->roles()->count());
        $this->assertSame($delivery->created_by, $user->roles()->sole()->pivot->assigned_by);
        $this->assertSame(0, Mail::mailer()->getSymfonyTransport()->messages()->count());
        $this->assertSame('PENDING', $delivery->status);
        $this->assertEqualsWithDelta(86400, now('UTC')->diffInSeconds($delivery->expires_at), 2);
        $this->assertSame(1, AuditEvent::where('action', 'employee.provisioned')->count());
    }

    public function test_temporary_secrets_are_distinct_hashed_and_expiring(): void
    {
        $first = $this->provision();
        $second = $this->provision();
        $sender = app(EmployeeAccessSender::class);
        $this->assertTrue($sender->send($first->id));
        $this->assertTrue($sender->send($second->id));
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        preg_match('/Mot de passe temporaire : ([a-f0-9]{48})/u', $messages[0]->getOriginalMessage()->getTextBody(), $a);
        preg_match('/Mot de passe temporaire : ([a-f0-9]{48})/u', $messages[1]->getOriginalMessage()->getTextBody(), $b);
        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $this->assertNotSame($a[1], $b[1]);
        $this->assertTrue(Hash::check($a[1], User::findOrFail($first->user_id)->password));
        $this->assertTrue(Hash::check($b[1], User::findOrFail($second->user_id)->password));
        $this->assertStringNotContainsString($a[1], json_encode(DB::table('employee_access_deliveries')->get()));
        $this->assertStringNotContainsString($a[1], AuditEvent::all()->toJson());
        $this->assertStringContainsString('https://staff.example.com', $messages[0]->getOriginalMessage()->getTextBody());
        $this->assertSame(User::findOrFail($first->user_id)->email, $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_lifetime_is_configurable_and_exact_boundary_prevents_delivery(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['employee_access.validity_hours' => 8]);
        $delivery = $this->provision();
        $this->assertSame(now()->addHours(8)->toISOString(), $delivery->expires_at->toISOString());
        $this->travel(8)->hours();
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        $this->assertSame('EXPIRED', $delivery->fresh()->status);
        $this->assertSame(0, Mail::mailer()->getSymfonyTransport()->messages()->count());
    }

    public function test_failed_send_retries_without_extending_expiry_or_creating_accounts(): void
    {
        $delivery = $this->provision();
        $expiry = $delivery->expires_at->toISOString();
        $manager = Mail::getFacadeRoot();
        $pending = Mockery::mock();
        $secret = null;
        $pending->shouldReceive('send')->once()->andReturnUsing(function ($mail) use (&$secret) {
            $secret = $mail->content()->with['temporaryPassword'];
            throw new RuntimeException('secret-in-error:'.$secret);
        });
        Mail::shouldReceive('to')->once()->andReturn($pending);
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        Mail::swap($manager);
        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->assertStringNotContainsString($secret, AuditEvent::all()->toJson());
        $this->assertStringNotContainsString($secret, json_encode(DB::table('employee_access_deliveries')->get()));
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        $this->travel(5)->minutes();
        $this->assertTrue(app(EmployeeAccessSender::class)->send($delivery->id));
        $user = User::findOrFail($delivery->user_id);
        $this->assertFalse(Hash::check($secret, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame($expiry, $delivery->fresh()->expires_at->toISOString());
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('employee_access_deliveries', 1);
    }

    public function test_log_transport_is_never_given_a_temporary_password(): void
    {
        $delivery = $this->provision();
        $oldHash = User::findOrFail($delivery->user_id)->password;
        config(['mail.default' => 'log']);
        Mail::shouldReceive('to')->never();
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        $this->assertSame('RETRY', $delivery->fresh()->status);
        $this->assertSame($oldHash, User::findOrFail($delivery->user_id)->password);
    }

    public function test_account_and_roles_roll_back_if_delivery_intent_cannot_be_saved(): void
    {
        $this->actor();
        $role = Role::factory()->create();
        EmployeeAccessDelivery::creating(function (): void {
            throw new RuntimeException('Outbox unavailable');
        });
        $this->postJson('/api/v1/users', ['email' => 'employee@example.com', 'role_ids' => [$role->id]])->assertStatus(500);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('employee_access_deliveries', 0);
        $this->assertSame(0, AuditEvent::where('result', 'SUCCESS')->count());
    }

    public function test_audit_failure_rolls_back_provisioning(): void
    {
        $this->actor();
        $role = Role::factory()->create();
        AuditEvent::creating(function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        $this->postJson('/api/v1/users', ['email' => 'employee@example.com', 'role_ids' => [$role->id]])->assertStatus(500);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('employee_access_deliveries', 0);
    }

    public function test_guest_and_creator_without_assignment_permission_are_denied(): void
    {
        $role = Role::factory()->create();
        $payload = ['email' => 'employee@example.com', 'role_ids' => [$role->id]];
        $this->postJson('/api/v1/users', $payload)->assertUnauthorized();
        $actor = User::factory()->create();
        $this->actingAs($actor, 'web');
        Gate::define('USER_CREATE', fn (User $user): bool => true);
        $this->postJson('/api/v1/users', $payload)->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_manual_password_protected_roles_and_duplicate_email_are_rejected(): void
    {
        $this->actor();
        $role = Role::factory()->create();
        $payload = ['email' => 'employee@example.com', 'role_ids' => [$role->id]];
        $this->postJson('/api/v1/users', $payload + ['password' => 'chosen-password'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $protected = Role::factory()->create(['is_protected' => true]);
        $this->postJson('/api/v1/users', ['email' => 'employee@example.com', 'role_ids' => [$protected->id]])->assertUnprocessable();
        $this->postJson('/api/v1/users', $payload)->assertCreated();
        $this->postJson('/api/v1/users', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('employee_access_deliveries', 1);
    }

    public function test_temporary_accounts_cannot_reach_business_routes_but_can_logout(): void
    {
        $delivery = $this->provision();
        Auth::forgetGuards();
        $this->actingAs(User::findOrFail($delivery->user_id), 'web');
        $this->getJson('/api/v1/user')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/v1/roles')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->postJson('/api/v1/logout')->assertNoContent();
    }

    public function test_status_requires_reset_permission_and_omits_secrets(): void
    {
        $delivery = $this->provision();
        $this->getJson('/api/v1/employee-access-deliveries/'.$delivery->id)->assertForbidden();
        Gate::define('USER_RESET_ACCESS', fn (User $user): bool => true);
        $response = $this->getJson('/api/v1/employee-access-deliveries/'.$delivery->id)->assertOk();
        $this->assertSame(['deliveryId', 'userId', 'status', 'attempts', 'expiresAt', 'sentAt', 'lastErrorCode', 'mustChangePassword', 'accessVersion'], array_keys($response->json('data')));
    }

    public function test_sender_cannot_run_before_commit(): void
    {
        $delivery = $this->provision();
        $this->expectException(\LogicException::class);
        DB::beginTransaction();
        try {
            app(EmployeeAccessSender::class)->send($delivery->id);
        } finally {
            DB::rollBack();
        }
    }

    public function test_sent_deliveries_are_not_sent_again_and_command_is_bounded(): void
    {
        $delivery = $this->provision();
        $this->artisan('employees:send-access')->assertSuccessful();
        $this->artisan('employees:send-access')->assertSuccessful();
        $this->assertSame(1, Mail::mailer()->getSymfonyTransport()->messages()->count());
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->artisan('employees:send-access --limit=0')->assertExitCode(2);
    }

    public function test_invalid_lifetime_fails_without_creating_employee(): void
    {
        $this->actor();
        $role = Role::factory()->create();
        config(['employee_access.validity_hours' => 0]);
        $this->postJson('/api/v1/users', ['email' => 'employee@example.com', 'role_ids' => [$role->id]])->assertStatus(503);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_access_deliveries', 0);
    }

    public function test_generated_email_access_completes_first_login_and_cannot_be_reused(): void
    {
        $delivery = $this->provision();
        $user = User::findOrFail($delivery->user_id);
        $roles = $user->roles()->pluck('roles.id')->all();
        $this->assertTrue(app(EmployeeAccessSender::class)->send($delivery->id));
        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        preg_match('/Mot de passe temporaire : ([a-f0-9]{48})/u', $message->getTextBody(), $match);
        $this->assertNotEmpty($match);
        $this->postJson('/api/v1/logout')->assertNoContent();
        Auth::forgetGuards();
        $login = $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => $match[1]])
            ->assertOk()->assertJsonPath('data.mustChangePassword', true);
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->withHeader('Origin', 'http://localhost:8090')->getJson('/api/v1/user')
            ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $newPassword = 'personal-employee-password';
        $this->postJson('/api/v1/temporary-password', [
            'current_password' => $match[1], 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertOk()->assertJsonPath('data.requiresLogin', true);
        $this->assertFalse(app(EmployeeAccessSender::class)->send($delivery->id));
        $this->assertSame(1, Mail::mailer()->getSymfonyTransport()->messages()->count());
        $this->assertSame($roles, $user->fresh()->roles()->pluck('roles.id')->all());
        Auth::forgetGuards();
        $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => $match[1]])->assertUnauthorized();
        $login = $this->postJson('/api/v1/employee-login', ['email' => $user->email, 'password' => $newPassword])
            ->assertOk()->assertJsonPath('data.mustChangePassword', false);
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('employee_access_deliveries', 1);
    }

    private function provision(): EmployeeAccessDelivery
    {
        $this->actor();
        $role = Role::factory()->create();
        $email = fake()->unique()->safeEmail();
        $response = $this->postJson('/api/v1/users', ['email' => $email, 'role_ids' => [$role->id]])->assertCreated();
        $user = User::where('email', $email)->sole();
        $this->assertStringNotContainsString($user->password, $response->getContent());

        return EmployeeAccessDelivery::findOrFail($response->json('data.deliveryId'));
    }

    private function actor(): void
    {
        $actor = auth('web')->user() ?? User::factory()->create();
        $this->actingAs($actor, 'web');
        Gate::define('USER_CREATE', fn (User $user): bool => $user->id === $actor->id);
        Gate::define('USER_ASSIGN_ROLE', fn (User $user): bool => $user->id === $actor->id);
    }
}
