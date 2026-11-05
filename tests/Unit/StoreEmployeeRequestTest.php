<?php

namespace Tests\Unit;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\PresenceVerifierInterface;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreEmployeeRequestTest extends TestCase
{
    private const ROLE_A = 'a0000000-0000-4000-8000-000000000001';

    private const ROLE_B = 'b0000000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $presence = Mockery::mock(PresenceVerifierInterface::class);
        $presence->shouldReceive('getCount')->andReturnUsing(function (string $table, string $column, mixed $value): int {
            return match ($table) {
                'users' => $value === 'taken@example.com' ? 1 : 0,
                'roles' => in_array(strtolower((string) $value), [self::ROLE_A, self::ROLE_B], true) ? 1 : 0,
                default => 0,
            };
        });
        $this->app['validator']->setPresenceVerifier($presence);
    }

    public function test_authorized_creator_can_submit_email_and_existing_roles_without_password(): void
    {
        $payload = ['email' => 'employee@example.com', 'role_ids' => [self::ROLE_A, self::ROLE_B]];

        $this->assertSame($payload, $this->validateEmployee($payload));
    }

    public function test_guest_cannot_create_an_employee(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->validateEmployee($this->payload(), guest: true);
    }

    #[DataProvider('insufficientPermissions')]
    public function test_create_and_role_assignment_permissions_are_required_separately(array $permissions): void
    {
        $this->expectException(AuthorizationException::class);
        $this->validateEmployee($this->payload(), $permissions);
    }

    public static function insufficientPermissions(): array
    {
        return [[[]], [['USER_CREATE']], [['USER_ASSIGN_ROLE']]];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_employee_fields_are_rejected(array $changes, string $field): void
    {
        $this->assertRejected(array_replace($this->payload(), $changes), $field);
    }

    public static function invalidPayloads(): array
    {
        return [
            [['email' => null], 'email'],
            [['email' => 'invalid'], 'email'],
            [['email' => 'taken@example.com'], 'email'],
            [['email' => str_repeat('a', 256).'@example.com'], 'email'],
            [['role_ids' => null], 'role_ids'],
            [['role_ids' => []], 'role_ids'],
            [['role_ids' => 'role'], 'role_ids'],
            [['role_ids' => ['selected' => self::ROLE_A]], 'role_ids'],
            [['role_ids' => ['invalid-id']], 'role_ids.0'],
            [['role_ids' => ['c0000000-0000-4000-8000-000000000003']], 'role_ids.0'],
            [['role_ids' => [self::ROLE_A, self::ROLE_A]], 'role_ids.0'],
            [['role_ids' => [self::ROLE_A, strtoupper(self::ROLE_A)]], 'role_ids.0'],
            [['role_ids' => [['id' => self::ROLE_A]]], 'role_ids.0'],
        ];
    }

    #[DataProvider('forbiddenFields')]
    public function test_client_cannot_supply_secrets_or_server_owned_state_even_as_null(string $field): void
    {
        $this->assertRejected($this->payload() + [$field => null], $field);
        $this->assertRejected($this->payload() + [$field => 'injected'], $field);
    }

    public static function forbiddenFields(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'password', 'temporary_password', 'password_confirmation', 'must_change_password',
            'expires_at', 'is_active', 'user_id', 'approval', 'permissions', 'name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRejected(array $payload, string $field): void
    {
        try {
            $this->validateEmployee($payload);
            $this->fail('Expected an employee validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string>  $permissions
     * @return array<string, mixed>
     */
    private function validateEmployee(array $payload, array $permissions = ['USER_CREATE', 'USER_ASSIGN_ROLE'], bool $guest = false): array
    {
        Gate::define('USER_CREATE', fn (User $user): bool => in_array('USER_CREATE', $permissions, true));
        Gate::define('USER_ASSIGN_ROLE', fn (User $user): bool => in_array('USER_ASSIGN_ROLE', $permissions, true));
        $user = $guest ? null : User::factory()->make(['id' => 1]);
        $request = StoreEmployeeRequest::create('/api/v1/users', 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request->validated();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['email' => 'employee@example.com', 'role_ids' => [self::ROLE_A]];
    }
}
