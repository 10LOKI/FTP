<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SalesOrderRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach (['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE'] as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]);
            $role->permissions()->attach($permission);
        }
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        $this->actingAs($actor);

        return $actor;
    }

    private function claimed(): int
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $id = DB::table('customer_orders')->insertGetId(['user_id' => $profile->user_id, 'number' => 'CMD-'.Str::ulid(),
            'status' => 'SOUMISE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => '{}', 'contact' => '{}',
            'delivery_address' => json_encode(['address' => 'Originale', 'city' => 'Rabat', 'country' => 'Maroc']),
            'billing_address' => '{}', 'lines' => '[]', 'catalogue_subtotal' => '10.00', 'created_at' => now(), 'updated_at' => now()]);
        $this->actor();
        $this->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertOk();

        return $id;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['expected_version' => 2, 'request_key' => (string) Str::uuid(), 'kind' => 'delivery_address',
            'changes' => ['address' => 'Proposée', 'city' => 'Rabat', 'country' => 'Maroc'], 'reason' => 'Correction demandée lors de l’appel'];
    }

    public function test_revisions_preserve_originals_and_append_attributed_before_after_history(): void
    {
        $id = $this->claimed();
        $before = DB::table('customer_orders')->where('id', $id)->first();
        $payload = $this->payload();
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertOk()
            ->assertJsonPath('data.version', 3)->assertJsonPath('data.requires_agreement', true);
        $second = array_replace($payload, ['expected_version' => 3, 'request_key' => (string) Str::uuid(),
            'changes' => ['address' => 'Autre proposition', 'city' => 'Rabat', 'country' => 'Maroc']]);
        $this->postJson("/api/v1/sales/orders/$id/revisions", $second)->assertOk()->assertJsonPath('data.version', 4);
        $this->getJson("/api/v1/sales/orders/$id")->assertOk()->assertJsonCount(2, 'data.revisions')
            ->assertJsonPath('data.revisions.0.before.address', 'Originale')
            ->assertJsonPath('data.revisions.0.after.address', 'Proposée')
            ->assertJsonPath('data.revisions.1.before.address', 'Proposée');
        $after = DB::table('customer_orders')->where('id', $id)->first();
        $this->assertSame($before->delivery_address, $after->delivery_address);
        $this->assertSame($before->lines, $after->lines);
        $this->assertSame($before->catalogue_subtotal, $after->catalogue_subtotal);
        $this->assertSame('EN_VERIFICATION', $after->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'sales.revision_proposed']);
    }

    public function test_replay_conflicts_and_stale_versions_do_not_duplicate_proposals(): void
    {
        $id = $this->claimed();
        $payload = $this->payload();
        $revision = $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertOk()->json('data.revision_id');
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertOk()->assertJsonPath('data.revision_id', $revision)
            ->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/sales/orders/$id/revisions", array_replace($payload, ['reason' => 'Différent']))->assertConflict();
        $this->postJson("/api/v1/sales/orders/$id/revisions", array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseCount('sales_order_revisions', 1);
    }

    public function test_other_agents_invalid_fields_and_late_states_are_denied(): void
    {
        $id = $this->claimed();
        $owner = auth()->user();
        $payload = $this->payload();
        $this->actor();
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertForbidden();
        $this->actingAs($owner);
        $invalid = $payload;
        $invalid['changes']['net_purchase_price'] = '1.00';
        $this->postJson("/api/v1/sales/orders/$id/revisions", $invalid)->assertUnprocessable();
        $this->postJson("/api/v1/sales/orders/$id/revisions", array_replace($payload, ['kind' => 'lines']))->assertUnprocessable();
        DB::table('customer_orders')->where('id', $id)->update(['status' => 'CONFIRMEE']);
        $this->postJson("/api/v1/sales/orders/$id/revisions", $payload)->assertConflict();
        $this->assertDatabaseCount('sales_order_revisions', 0);
    }

    public function test_audit_failure_rolls_back_proposal_and_version(): void
    {
        $id = $this->claimed();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'sales.revision_proposed') {
                throw new RuntimeException('Simulated audit failure');
            }
        });
        try {
            $this->postJson("/api/v1/sales/orders/$id/revisions", $this->payload())->assertServerError();
            $this->assertDatabaseCount('sales_order_revisions', 0);
            $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 2]);
        } finally {
            AuditEvent::flushEventListeners();
        }
    }
}
