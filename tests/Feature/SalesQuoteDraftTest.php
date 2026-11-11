<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SalesQuoteDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
        DB::table('pricing_settings')->where('id', 1)->update(['currency' => 'MAD', 'tax_basis' => 'TTC']);
    }

    private function employee(bool $manage = true): User
    {
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach ($manage ? ['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE'] : ['ORDER_REVIEW'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]));
        }
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);

        return $actor;
    }

    /** @return array{int, Product, User, CustomerProfile} */
    private function scenario(): array
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        CustomerDiscount::factory()->for($profile)->create(['percentage' => '10.00']);
        $id = DB::table('customer_orders')->insertGetId(['user_id' => $profile->user_id, 'number' => 'CMD-'.Str::ulid(),
            'status' => 'SOUMISE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'customer_snapshot' => '{}', 'contact' => '{}', 'delivery_address' => '{"address":"Originale"}', 'billing_address' => '{}',
            'lines' => json_encode([['id' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => 2, 'units_per_package' => 1]]),
            'catalogue_subtotal' => '200.00', 'created_at' => now(), 'updated_at' => now()]);
        $actor = $this->employee();
        $this->actingAs($actor)->postJson("/api/v1/sales/orders/$id/claim", ['expected_version' => 1])->assertOk();

        return [$id, $product, $actor, $profile];
    }

    /** @return array<string, mixed> */
    private function payload(int $id, int $version = 2): array
    {
        $input = ['expected_version' => $version, 'valid_until' => now('UTC')->addWeek()->toDateString()];
        $preview = $this->postJson("/api/v1/sales/orders/$id/quote-preview", $input)->assertOk()->json('data');

        return $input + ['request_key' => (string) Str::uuid(), 'preview_token' => $preview['preview_token']];
    }

    public function test_drafts_preserve_history_recalculate_real_prices_and_leave_originals_and_stock_untouched(): void
    {
        [$id, $product] = $this->scenario();
        $original = DB::table('customer_orders')->where('id', $id)->first();
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $this->payload($id))->assertOk()->assertJsonPath('data.revision', 1);
        $first = $this->getJson("/api/v1/sales/orders/$id")->assertOk()->json('data.quote_drafts.0.snapshot');
        $this->assertSame('180.00', $first['estimated_subtotal']);
        $this->assertSame('MAD', $first['currency']);
        $this->assertSame('TTC', $first['tax_basis']);
        $this->assertNull($first['payment_terms']);
        $this->assertSame('DRAFT', $first['status']);
        $product->forceFill(['sale_price' => '200.00'])->save();
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $this->payload($id, 3))->assertOk()->assertJsonPath('data.revision', 2);
        $history = $this->getJson("/api/v1/sales/orders/$id")->assertOk()->assertJsonCount(2, 'data.quote_drafts')->json('data.quote_drafts');
        $this->assertSame($first, $history[0]['snapshot']);
        $this->assertSame('360.00', $history[1]['snapshot']['estimated_subtotal']);
        $after = DB::table('customer_orders')->where('id', $id)->first();
        $this->assertSame($original->lines, $after->lines);
        $this->assertSame($original->delivery_address, $after->delivery_address);
        $this->assertSame($original->catalogue_subtotal, $after->catalogue_subtotal);
        $this->assertSame('EN_VERIFICATION', $after->status);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'sales.quote_draft_created']);
    }

    public function test_draft_uses_latest_proposed_address_and_lines_with_original_line_identity(): void
    {
        [$id, $product] = $this->scenario();
        $lineId = json_decode(DB::table('customer_orders')->where('id', $id)->value('lines'), true)[0]['id'];
        foreach (['Première', 'Dernière'] as $offset => $address) {
            $this->postJson("/api/v1/sales/orders/$id/revisions", ['kind' => 'delivery_address', 'expected_version' => 2 + $offset,
                'request_key' => (string) Str::uuid(), 'changes' => ['address' => $address, 'city' => 'Rabat', 'country' => 'Maroc'], 'reason' => 'Correction'])->assertOk();
        }
        $lines = [['product_id' => $product->id, 'quantity' => 3, 'units_per_package' => 1]];
        $preview = $this->postJson("/api/v1/sales/orders/$id/line-preview", ['lines' => $lines, 'expected_version' => 4])->assertOk()->json('data');
        $this->postJson("/api/v1/sales/orders/$id/revisions", ['kind' => 'lines', 'expected_version' => 4, 'request_key' => (string) Str::uuid(),
            'changes' => ['lines' => $lines], 'pricing_token' => $preview['pricing_token'], 'reason' => 'Quantité'])->assertOk();
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $this->payload($id, 5))->assertOk();
        $this->getJson("/api/v1/sales/orders/$id")->assertOk()->assertJsonPath('data.quote_drafts.0.snapshot.delivery_address.address', 'Dernière')
            ->assertJsonPath('data.quote_drafts.0.snapshot.lines.0.quantity', 3)->assertJsonPath('data.quote_drafts.0.snapshot.lines.0.id', $lineId);
    }

    public function test_replays_are_idempotent_and_changed_keys_or_stale_previews_are_rejected(): void
    {
        [$id, $product] = $this->scenario();
        $payload = $this->payload($id);
        $product->forceFill(['sale_price' => '200.00'])->save();
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertConflict();
        $payload = $this->payload($id);
        $first = $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertOk()->json('data.draft_id');
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertOk()->assertJsonPath('data.draft_id', $first)->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", array_replace($payload, ['preview_token' => str_repeat('0', 64)]))->assertConflict();
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseCount('sales_quote_drafts', 1);
    }

    public function test_mutations_require_claim_owner_permission_and_employee_identity(): void
    {
        [$id, , $owner, $profile] = $this->scenario();
        $payload = $this->payload($id);
        $this->actingAs($this->employee())->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertForbidden();
        $this->actingAs($this->employee(false))->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertForbidden();
        DB::table('sales_order_reviews')->where('order_id', $id)->update(['claimed_by' => $profile->user_id]);
        $profile->user->roles()->attach($owner->roles()->first(), ['assigned_by' => $owner->id, 'assigned_at' => now()]);
        $this->actingAs($profile->user)->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertForbidden();
        $this->getJson("/api/v1/sales/orders/$id")->assertForbidden();
        $this->assertDatabaseCount('sales_quote_drafts', 0);
    }

    public function test_invalid_dates_unavailable_prices_and_late_states_cannot_create_drafts(): void
    {
        [$id, $product] = $this->scenario();
        $payload = $this->payload($id);
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", array_replace($payload, ['valid_until' => now('UTC')->toDateString()]))->assertUnprocessable();
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload + ['payment_terms' => ['mode' => 'cash']])->assertUnprocessable();
        $product->forceFill(['publication' => 'draft'])->save();
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertUnprocessable();
        DB::table('customer_orders')->where('id', $id)->update(['status' => 'CONFIRMEE']);
        $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertConflict();
        $this->assertDatabaseCount('sales_quote_drafts', 0);
    }

    public function test_failed_audit_rolls_back_version_and_draft(): void
    {
        [$id] = $this->scenario();
        $payload = $this->payload($id);
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'sales.quote_draft_created') {
                throw new RuntimeException('Simulated audit failure');
            }
        });
        try {
            $this->postJson("/api/v1/sales/orders/$id/quote-drafts", $payload)->assertServerError();
            $this->assertDatabaseCount('sales_quote_drafts', 0);
            $this->assertDatabaseHas('customer_orders', ['id' => $id, 'version' => 2]);
        } finally {
            AuditEvent::flushEventListeners();
        }
    }
}
