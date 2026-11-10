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

class SalesOrderReviewTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $manage = true): User
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach ($manage ? ['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE'] : ['ORDER_REVIEW'] as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]);
            $role->permissions()->attach($permission);
        }
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        $this->actingAs($actor);

        return $actor;
    }

    private function order(): int
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);

        return DB::table('customer_orders')->insertGetId(['user_id' => $profile->user_id, 'number' => 'CMD-'.Str::ulid(),
            'status' => 'SOUMISE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{}', 'billing_address' => '{}',
            'lines' => '[]', 'catalogue_subtotal' => '10.00', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_claim_is_attributed_versioned_and_idempotent_without_changing_snapshots(): void
    {
        $id = $this->order();
        $before = DB::table('customer_orders')->where('id', $id)->first();
        $actor = $this->actor();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertOk()
            ->assertJsonPath('data.status', 'EN_VERIFICATION')->assertJsonPath('data.version', 2);
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertOk();
        $this->assertDatabaseCount('sales_order_reviews', 1);
        $this->assertDatabaseHas('sales_order_reviews', ['order_id' => $id, 'claimed_by' => $actor->id]);
        $after = DB::table('customer_orders')->where('id', $id)->first();
        $this->assertSame($before->lines, $after->lines);
        $this->assertSame($before->commercial_snapshot, $after->commercial_snapshot);
        $this->assertSame($before->catalogue_subtotal, $after->catalogue_subtotal);
        $this->assertDatabaseHas('audit_events', ['action' => 'sales.order_claimed', 'actor_id' => $actor->id]);
    }

    public function test_call_replay_and_conflicting_payload_preserve_a_single_record(): void
    {
        $id = $this->order();
        $actor = $this->actor();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertOk();
        $payload = ['expected_version' => 2, 'request_key' => (string) Str::uuid(), 'outcome' => 'AGREEMENT',
            'happened_at' => now('UTC')->subMinute()->toIso8601String(), 'note' => 'Appel de test'];
        $first = $this->postJson("/api/v1/sales/orders/$id/calls", $payload)->assertOk()->assertJsonPath('data.version', 3)->json('data.call_id');
        $this->postJson("/api/v1/sales/orders/$id/calls", $payload)->assertOk()->assertJsonPath('data.call_id', $first)->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/sales/orders/$id/calls", array_replace($payload, ['note' => 'Different']))->assertConflict();
        $this->assertDatabaseCount('sales_order_calls', 1);
        $this->assertDatabaseHas('sales_order_calls', ['actor_id' => $actor->id, 'order_version' => 3]);
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'status' => 'EN_VERIFICATION', 'version' => 3]);
        $this->getJson("/api/v1/sales/orders/$id")->assertOk()->assertJsonCount(1, 'data.calls');
    }

    public function test_read_permission_does_not_grant_write_and_other_agents_cannot_take_over(): void
    {
        $id = $this->order();
        $this->actor(false);
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertForbidden();
        $this->actor();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 0])->assertUnprocessable();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 2])->assertConflict();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertOk();
        $this->actor();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 2])->assertConflict();
        $this->postJson("/api/v1/sales/orders/$id/calls", ['expected_version' => 2, 'request_key' => (string) Str::uuid(),
            'outcome' => 'JOINED', 'happened_at' => now('UTC')->subMinute()->toIso8601String()])->assertForbidden();
        $this->assertDatabaseCount('sales_order_calls', 0);
    }

    public function test_invalid_calls_and_stale_versions_do_not_change_the_order(): void
    {
        $id = $this->order();
        $this->actor();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertOk();
        $payload = ['expected_version' => 1, 'request_key' => (string) Str::uuid(), 'outcome' => 'JOINED',
            'happened_at' => now('UTC')->subMinute()->toIso8601String()];
        $this->postJson("/api/v1/sales/orders/$id/calls", $payload)->assertConflict();
        $this->postJson("/api/v1/sales/orders/$id/calls", array_replace($payload, ['outcome' => 'INVALID']))->assertUnprocessable();
        $this->postJson("/api/v1/sales/orders/$id/calls", $payload + ['actor_id' => 999])->assertUnprocessable();
        $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 2]);
        $this->assertDatabaseCount('sales_order_calls', 0);
    }
}
