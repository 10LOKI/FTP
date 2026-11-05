<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RoleCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_users_without_assignment_permission_cannot_read_roles(): void
    {
        $this->getJson('/api/v1/roles')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_authorized_catalog_exposes_only_ordinary_role_identifiers_and_labels(): void
    {
        $this->signInAssigner();
        $ordinary = Role::factory()->create(['code' => 'WAREHOUSE', 'label' => 'Warehouse']);
        Role::factory()->create(['is_protected' => true, 'code' => 'PROTECTED_ADMIN']);
        $response = $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame([['id' => $ordinary->id, 'code' => 'WAREHOUSE', 'label' => 'Warehouse']], $response->json('data'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_catalog_is_paginated_and_rejects_invalid_or_privileged_filters(): void
    {
        $this->signInAssigner();
        Role::factory()->count(3)->create();
        $this->getJson('/api/v1/roles?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/roles?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/roles?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/roles?is_protected=1')->assertUnprocessable();
    }

    public function test_empty_catalog_is_a_valid_empty_list(): void
    {
        $this->signInAssigner();
        $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_permission_is_rechecked_after_it_is_withdrawn(): void
    {
        $this->signInAssigner();
        $this->getJson('/api/v1/roles')->assertOk();
        Gate::define('USER_ASSIGN_ROLE', fn (User $user): bool => false);
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    private function signInAssigner(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor, 'web');
        Gate::define('USER_ASSIGN_ROLE', fn (User $user): bool => $user->id === $actor->id);
    }
}
