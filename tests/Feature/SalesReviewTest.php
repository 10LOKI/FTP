<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReviewTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Role
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['code' => 'ORDER_REVIEW']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role, ['assigned_by' => $user->id, 'assigned_at' => now()]);
        $this->actingAs($user);

        return $role;
    }

    private function order(string $number, string $code): int
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);

        return DB::table('customer_orders')->insertGetId([
            'user_id' => $profile->user_id, 'number' => $number, 'status' => 'SOUMISE', 'version' => 1,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => json_encode(['customer_code' => $code, 'type' => 'PARTICULIER', 'internal_notes' => 'PRIVATE-MARKER']),
            'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}', 'lines' => '[]',
            'catalogue_subtotal' => '10.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_real_permission_filters_and_safe_projection_are_used(): void
    {
        $id = $this->order('CMD-ONE', 'CLI-ONE');
        $this->order('CMD-TWO', 'CLI-TWO');
        $role = $this->employee();
        $this->getJson('/api/v1/sales/orders?customer_code=CLI-ONE&status=SOUMISE')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'CMD-ONE');
        $response = $this->getJson('/api/v1/sales/orders/'.$id)->assertOk()
            ->assertJsonPath('data.customer.customer_code', 'CLI-ONE')
            ->assertJsonPath('data.payment_terms_status', 'contract_pending')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('PRIVATE-MARKER', $response->getContent());
        $role->permissions()->detach();
        $this->getJson('/api/v1/sales/orders')->assertForbidden();
    }

    public function test_guests_customers_and_employees_without_permission_are_refused(): void
    {
        $this->getJson('/api/v1/sales/orders')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/sales/orders')->assertForbidden();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user)->getJson('/api/v1/sales/orders')->assertForbidden();
    }

    public function test_invalid_filters_missing_detail_and_page_use_real_routes(): void
    {
        $this->employee();
        $this->getJson('/api/v1/sales/orders?status=INVALID')->assertUnprocessable();
        $this->getJson('/api/v1/sales/orders?from=2026-11-10&to=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/v1/sales/orders/999999')->assertNotFound();
        $this->withoutVite()->get('/gestion/commandes')->assertOk()->assertSee('sales-review');
    }
}
