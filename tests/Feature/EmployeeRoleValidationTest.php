<?php

namespace Tests\Feature;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeRoleValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_database_roles_are_accepted_without_mocked_presence_checks(): void
    {
        $actor = $this->reviewer();
        $roles = Role::factory()->count(2)->create();
        $payload = ['email' => 'employee@example.com', 'role_ids' => $roles->modelKeys()];

        $this->assertSame($payload, $this->validateEmployee($actor, $payload));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertTrue(Str::isUuid($roles->first()->id));
    }

    public function test_missing_database_role_is_rejected(): void
    {
        $actor = $this->reviewer();
        $this->expectValidationError($actor, [
            'email' => 'employee@example.com', 'role_ids' => [(string) Str::uuid()],
        ], 'role_ids.0');
    }

    public function test_an_existing_account_email_is_rejected_with_real_database_lookup(): void
    {
        $actor = $this->reviewer();
        $role = Role::factory()->create();
        User::factory()->create(['email' => 'employee@example.com']);
        $this->expectValidationError($actor, [
            'email' => 'employee@example.com', 'role_ids' => [$role->id],
        ], 'email');
    }

    public function test_duplicate_role_ids_are_rejected_with_real_database_lookup(): void
    {
        $actor = $this->reviewer();
        $role = Role::factory()->create();
        $this->expectValidationError($actor, [
            'email' => 'employee@example.com', 'role_ids' => [$role->id, $role->id],
        ], 'role_ids.0');
    }

    public function test_role_membership_alone_does_not_grant_employee_creation_permissions(): void
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create(['code' => 'ADMIN']);
        $actor->roles()->attach($role->id, ['assigned_by' => $actor->id, 'assigned_at' => now('UTC')]);

        $this->expectException(AuthorizationException::class);
        $this->validateEmployee($actor, ['email' => 'employee@example.com', 'role_ids' => [$role->id]]);
    }

    public function test_assignment_records_author_time_and_both_relationships(): void
    {
        $actor = User::factory()->create();
        $member = User::factory()->create();
        $role = Role::factory()->create();
        $assignedAt = now('UTC')->startOfSecond();
        $member->roles()->attach($role->id, ['assigned_by' => $actor->id, 'assigned_at' => $assignedAt]);

        $assignedRole = $member->roles()->sole();
        $this->assertSame($role->id, $assignedRole->id);
        $this->assertSame($actor->id, $assignedRole->pivot->assigned_by);
        $this->assertSame($assignedAt->format('Y-m-d H:i:s'), $assignedRole->pivot->assigned_at);
        $this->assertSame($member->id, $role->users()->sole()->id);
        $this->assertArrayNotHasKey('roles', $member->load('roles')->toArray());
        $this->assertArrayNotHasKey('users', $role->load('users')->toArray());
    }

    public function test_duplicate_assignment_is_rejected_by_the_database(): void
    {
        $actor = User::factory()->create();
        $member = User::factory()->create();
        $role = Role::factory()->create();
        $attributes = ['assigned_by' => $actor->id, 'assigned_at' => now('UTC')];
        $member->roles()->attach($role->id, $attributes);

        $this->expectException(QueryException::class);
        $member->roles()->attach($role->id, $attributes);
    }

    public function test_assigned_role_cannot_be_deleted_while_assignments_reference_it(): void
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        $actor->roles()->attach($role->id, ['assigned_by' => $actor->id, 'assigned_at' => now('UTC')]);

        $this->expectException(QueryException::class);
        $role->delete();
    }

    public function test_duplicate_role_code_is_rejected_by_the_database(): void
    {
        Role::factory()->create(['code' => 'WAREHOUSE']);
        $this->expectException(QueryException::class);
        Role::factory()->create(['code' => 'WAREHOUSE']);
    }

    public function test_assignment_cannot_reference_a_missing_role(): void
    {
        $actor = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('user_roles')->insert([
            'user_id' => $actor->id, 'role_id' => (string) Str::uuid(),
            'assigned_by' => $actor->id, 'assigned_at' => now('UTC'),
        ]);
    }

    public function test_assignment_cannot_reference_a_missing_author(): void
    {
        $member = User::factory()->create();
        $role = Role::factory()->create();
        $this->expectException(QueryException::class);
        $member->roles()->attach($role->id, ['assigned_by' => 99999999, 'assigned_at' => now('UTC')]);
    }

    public function test_public_registration_cannot_assign_an_existing_role(): void
    {
        $role = Role::factory()->create();
        $this->postJson('/api/v1/customer-registrations', [
            'type' => 'PARTICULIER', 'name' => 'Customer', 'phone' => '+212600000000',
            'email' => 'customer@example.com', 'password' => 'customer-password',
            'address' => 'Example Street', 'city' => 'Casablanca', 'country' => 'Morocco',
            'role_ids' => [$role->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_roles', 0);
    }

    public function test_protected_role_cannot_be_injected_into_employee_creation_input(): void
    {
        $actor = $this->reviewer();
        $role = Role::factory()->create(['is_protected' => true]);
        $this->expectValidationError($actor, [
            'email' => 'employee@example.com', 'role_ids' => [$role->id],
        ], 'role_ids.0');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 0);
    }

    public function test_mixing_ordinary_and_protected_roles_is_rejected(): void
    {
        $actor = $this->reviewer();
        $ordinary = Role::factory()->create();
        $protected = Role::factory()->create(['is_protected' => true]);
        $this->expectValidationError($actor, [
            'email' => 'employee@example.com', 'role_ids' => [$ordinary->id, $protected->id],
        ], 'role_ids.1');
        $this->assertDatabaseCount('user_roles', 0);
    }

    private function reviewer(): User
    {
        $actor = User::factory()->create();
        Gate::define('USER_CREATE', fn (User $user): bool => $user->id === $actor->id);
        Gate::define('USER_ASSIGN_ROLE', fn (User $user): bool => $user->id === $actor->id);

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateEmployee(User $actor, array $payload): array
    {
        $request = StoreEmployeeRequest::create('/api/v1/users', 'POST', $payload);
        $request->setUserResolver(fn () => $actor);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request->validated();
    }

    /** @param array<string, mixed> $payload */
    private function expectValidationError(User $actor, array $payload, string $field): void
    {
        try {
            $this->validateEmployee($actor, $payload);
            $this->fail('Expected database-backed validation to reject this field.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
