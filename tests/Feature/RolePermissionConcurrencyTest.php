<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EffectivePermissions;
use App\Services\RolePermissionChange;
use App\Services\RolePermissionConfirmation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class RolePermissionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')
            || DB::connection()->getDatabaseName() !== 'ftp13_verification') {
            $this->markTestSkipped('Requires pcntl and the dedicated disposable MySQL ftp13_verification database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
    }

    public function test_simultaneous_confirmation_replay_writes_once(): void
    {
        [$actor] = $this->administrator();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['code' => 'USER_CREATE']);
        $role->permissions()->attach($permission->id);
        $token = app(RolePermissionConfirmation::class)->issue($actor, $role, [])['token'];
        $operation = ['actor' => $actor->id, 'role' => $role->id, 'token' => $token];
        $statuses = $this->race([$operation, $operation]);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(2, Role::findOrFail($role->id)->version);
        $this->assertSame(0, $role->permissions()->count());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_two_administrators_cannot_concurrently_remove_all_administrative_access(): void
    {
        [$first, $firstRole] = $this->administrator();
        [$second, $secondRole] = $this->administrator();
        $operations = [];
        foreach ([[$first, $firstRole], [$second, $secondRole]] as [$actor, $role]) {
            $operations[] = ['actor' => $actor->id, 'role' => $role->id,
                'token' => app(RolePermissionConfirmation::class)->issue($actor, $role, [])['token']];
        }
        $this->assertSame([200, 409], $this->race($operations));
        $this->assertSame(1, Role::whereIn('id', [$firstRole->id, $secondRole->id])->where('version', 1)->count());
        $remaining = User::get()->filter(fn (User $user): bool => app(EffectivePermissions::class)->allows($user, 'ROLE_MANAGE')
            && app(EffectivePermissions::class)->allows($user, 'USER_ASSIGN_ROLE'));
        $this->assertCount(1, $remaining);
        $this->assertDatabaseCount('audit_events', 1);
    }

    /** @return array{User, Role} */
    private function administrator(): array
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        $actor->roles()->attach($role->id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        foreach (['ROLE_MANAGE', 'USER_ASSIGN_ROLE'] as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'access', 'label' => $code]);
            $role->permissions()->attach($permission->id);
        }

        return [$actor, $role];
    }

    /** @param list<array{actor: int, role: string, token: string}> $operations
     * @return list<int>
     */
    private function race(array $operations): array
    {
        DB::disconnect();
        $children = [];
        foreach ($operations as $operation) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $this->assertNotFalse($sockets);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                try {
                    app(RolePermissionChange::class)->apply(
                        User::findOrFail($operation['actor']), Role::findOrFail($operation['role']), 1, [], $operation['token'],
                    );
                    $result = ['status' => 200];
                } catch (Throwable $exception) {
                    $result = ['status' => $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500,
                        'error' => $exception->getMessage()];
                }
                fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                DB::disconnect();
                exit(0);
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 45);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }
        foreach ($children as $child) {
            fwrite($child['socket'], '1');
        }
        $statuses = [];
        foreach ($children as $child) {
            $result = json_decode(stream_get_contents($child['socket']), true, flags: JSON_THROW_ON_ERROR);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertContains($result['status'], [200, 409], $result['error'] ?? '');
            $statuses[] = $result['status'];
        }
        DB::reconnect();
        sort($statuses);

        return $statuses;
    }
}
