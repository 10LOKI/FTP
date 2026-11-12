<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerFinance;
use Database\Seeders\SalesReviewDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
            ->assertJsonPath('data.payment_terms_status', 'terms_required')
            ->assertJsonPath('data.payment_mode', null)
            ->assertJsonPath('data.can_claim', false)->assertJsonPath('data.can_manage', false)
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

    public function test_number_and_utc_date_filters_include_both_day_boundaries(): void
    {
        $first = $this->order('CMD-START', 'CLI-FILTER');
        $last = $this->order('CMD-END', 'CLI-FILTER');
        $outside = $this->order('CMD-OUTSIDE', 'CLI-FILTER');
        DB::table('customer_orders')->where('id', $first)->update(['created_at' => '2026-11-10 00:00:00']);
        DB::table('customer_orders')->where('id', $last)->update(['created_at' => '2026-11-10 23:59:59']);
        DB::table('customer_orders')->where('id', $outside)->update(['created_at' => '2026-11-11 00:00:00']);
        $this->employee();
        $this->getJson('/api/v1/sales/orders?from=2026-11-10&to=2026-11-10&customer_code=CLI-FILTER&status=SOUMISE')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.number', 'CMD-END')->assertJsonPath('data.1.number', 'CMD-START');
        $this->getJson('/api/v1/sales/orders?number=CMD-START')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $first);
        $this->getJson('/api/v1/sales/orders?number=CMD-STAR')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sales/orders?status=CONFIRMEE')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_review_reads_latest_real_account_terms_without_mutating_them_or_exposing_their_reason(): void
    {
        $id = $this->order('CMD-TERMS', 'CLI-TERMS');
        $otherId = $this->order('CMD-OTHER', 'CLI-OTHER');
        $role = $this->employee();
        $role->permissions()->attach(Permission::factory()->create(['code' => 'CUSTOMER_TERMS_MANAGE']));
        $customerId = (int) DB::table('customer_orders')->where('id', $id)->value('user_id');
        $actor = auth()->user();
        config(['receivables.enabled' => true]);
        foreach (['cash', 'cheque'] as $index => $mode) {
            app(CustomerFinance::class)->operate($actor, $customerId, 'terms', [
                'version' => $index + 1, 'request_key' => (string) Str::uuid(),
                'mode' => $mode, 'label' => 'Conditions approuvées', 'reason' => 'PRIVATE-TERMS-REASON',
            ]);
        }
        $response = $this->getJson('/api/v1/sales/orders/'.$id)->assertOk()
            ->assertJsonPath('data.payment_mode', 'cheque')->assertJsonPath('data.payment_terms_version', 3)
            ->assertJsonPath('data.payment_terms_status', 'available')->assertJsonPath('data.payment_policy_enabled', true);
        $this->assertStringNotContainsString('PRIVATE-TERMS-REASON', $response->getContent());
        $this->getJson('/api/v1/sales/orders/'.$otherId)->assertOk()->assertJsonPath('data.payment_mode', null);
        config(['receivables.enabled' => false]);
        $this->getJson('/api/v1/sales/orders/'.$id)->assertOk()
            ->assertJsonPath('data.payment_mode', 'cheque')->assertJsonPath('data.payment_policy_enabled', false);
        $this->assertFalse(config('receivables.enabled'));
        $this->assertDatabaseCount('customer_payment_terms', 2);
        $this->assertDatabaseHas('customer_finance_accounts', ['customer_id' => $customerId, 'version' => 3]);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'status' => 'SOUMISE', 'version' => 1]);
    }

    public function test_browser_fixtures_refuse_the_application_database(): void
    {
        $connection = DB::connection();
        $originalDatabase = $connection->getDatabaseName();
        try {
            $connection->setDatabaseName('ftp');
            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Use the isolated ftp_cart_tests database only.');
            $this->seed(SalesReviewDemoSeeder::class);
        } finally {
            $connection->setDatabaseName($originalDatabase);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('customer_orders', 0);
        }
    }

    public function test_isolated_demo_supports_real_employee_login_and_replays_without_duplicate_orders(): void
    {
        if (DB::connection()->getDatabaseName() !== 'ftp_cart_tests') {
            $this->markTestSkipped('Browser fixtures require the isolated ftp_cart_tests database.');
        }
        $this->seed(SalesReviewDemoSeeder::class);
        $this->seed(SalesReviewDemoSeeder::class);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('customer_orders', 1);
        $login = $this->postJson('/api/v1/employee-login', ['email' => 'ftp21-agent@example.test', 'password' => 'ftp21-isolated-demo-only'])->assertOk();
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        $this->withHeader('Origin', 'http://localhost:8090');
        $id = DB::table('customer_orders')->value('id');
        $this->getJson('/api/v1/sales/orders/'.$id)->assertOk()->assertJsonPath('data.can_claim', true)
            ->assertJsonPath('data.payment_mode', 'cheque');
        $this->postJson('/api/v1/sales/orders/'.$id.'/claim', ['expected_version' => 1])->assertOk();
        $this->getJson('/api/v1/sales/orders/'.$id)->assertOk()->assertJsonPath('data.can_manage', true);
    }
}
