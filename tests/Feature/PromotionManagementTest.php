<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PromotionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_is_closed_without_a_real_capability_and_blocked_clients_stay_refused(): void
    {
        $this->getJson('/api/v1/promotions')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/promotions')->assertForbidden();
        $profile = CustomerProfile::factory()->create(['approval' => 'REFUSE']);
        Gate::define('PROMOTION_MANAGE', fn (User $user): bool => true);
        $this->actingAs($profile->user)->getJson('/api/v1/promotions')->assertForbidden();
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_create_retry_and_key_conflict_are_atomic_audited_and_do_not_modify_the_catalogue_price(): void
    {
        $actor = $this->manager();
        $product = Product::factory()->create(['sale_price' => '100.00']);
        $values = $this->values($product);
        $key = (string) Str::uuid();
        $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/promotions', $values)->assertCreated()
            ->assertJsonPath('data.created_by', (string) $actor->id)->assertJsonPath('data.starts_at', '2026-11-05T09:00:00+00:00');
        $this->postJson('/api/v1/promotions', $values)->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->postJson('/api/v1/promotions', [...$values, 'value' => '70.00'])->assertConflict();
        $this->assertDatabaseCount('promotions', 1);
        $this->assertSame(1, AuditEvent::where('result', 'SUCCESS')->whereIn('action', ['promotion.created', 'promotion.updated', 'pricing.base_price_updated'])->count());
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => '100.00']);
        $this->assertDatabaseHas('audit_events', ['action' => 'promotion.created', 'actor_id' => $actor->id]);
        $this->assertStringNotContainsString('net_purchase_price', json_encode(AuditEvent::first()->changes));
    }

    public function test_enabled_overlaps_are_rejected_but_adjacent_windows_and_disabled_drafts_are_allowed(): void
    {
        $this->manager();
        $product = Product::factory()->create(['sale_price' => '100.00']);
        $values = $this->values($product);
        $this->createOffer($values)->assertCreated();
        $this->createOffer([...$values, 'starts_at' => '2026-11-05T09:30'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->createOffer([...$values, 'starts_at' => '2026-11-05T10:00', 'ends_at' => '2026-11-05T11:00'])->assertCreated();
        $this->createOffer([...$values, 'state' => 'draft'])->assertCreated();
        $this->createOffer([...$values, 'state' => 'disabled'])->assertCreated();
        $other = Product::factory()->create(['sale_price' => '100.00']);
        $this->createOffer([...$values, 'product_id' => $other->id])->assertCreated();
        $this->assertDatabaseCount('promotions', 5);
    }

    public function test_edit_conflicts_and_invalid_activation_preserve_the_existing_offer(): void
    {
        $this->manager();
        $product = Product::factory()->create(['sale_price' => '100.00']);
        $values = $this->values($product);
        $created = $this->createOffer([...$values, 'state' => 'draft'])->assertCreated()->json('data');
        unset($values['product_id']);
        $url = '/api/v1/promotions/'.$created['id'];
        $this->putJson($url, [...$values, 'expected_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($url, [...$values, 'state' => 'disabled', 'expected_version' => 1])->assertConflict();
        $this->putJson($url, [...$values, 'value' => '100.00', 'expected_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->putJson($url, [...$values, 'state' => 'disabled', 'expected_version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('promotions', ['id' => $created['id'], 'state' => 'disabled', 'value' => '80.00']);
        $this->assertSame(3, AuditEvent::where('result', 'SUCCESS')->whereIn('action', ['promotion.created', 'promotion.updated'])->count());
    }

    public function test_invalid_fields_dates_values_timezone_and_injections_produce_no_writes(): void
    {
        $this->manager();
        $product = Product::factory()->create(['sale_price' => '100.00']);
        $values = $this->values($product);
        foreach ([
            ['value' => '100.00'], ['value' => 80], ['value' => '1e2'], ['kind' => 'percentage', 'value' => '100.01'],
            ['ends_at' => $values['starts_at']], ['starts_at' => '2026-02-30T12:00'], ['timezone' => 'Europe/Paris'],
            ['created_by' => 999], ['sale_price' => '1'], ['version' => 200],
        ] as $changed) {
            $this->createOffer([...$values, ...$changed])->assertUnprocessable();
        }
        $this->assertDatabaseCount('promotions', 0);
        $this->assertSame(0, AuditEvent::where('result', 'SUCCESS')->whereIn('action', ['promotion.created', 'promotion.updated'])->count());
    }

    public function test_an_audit_storage_failure_rolls_back_the_offer_and_idempotency_key(): void
    {
        $this->manager();
        $product = Product::factory()->create(['sale_price' => '100.00']);
        AuditEvent::creating(function (): void {
            throw new \RuntimeException('Simulated audit outage');
        });

        $this->createOffer($this->values($product))->assertStatus(500);
        $this->assertDatabaseCount('promotions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_base_price_changes_cannot_invalidate_an_enabled_offer_and_revocation_is_immediate(): void
    {
        $this->manager();
        $this->freezeTime();
        $product = Product::factory()->create(['sale_price' => '100.00']);
        Promotion::factory()->for($product)->enabled()->create(['kind' => 'fixed_price', 'value' => '80.00']);
        Gate::define('PRODUCT_PRICE_UPDATE', fn (User $user): bool => true);
        $this->patchJson("/api/v1/products/{$product->id}/price", ['sale_price' => '70.00'])->assertUnprocessable()->assertJsonValidationErrors('sale_price');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => '100.00']);
        $this->patchJson("/api/v1/products/{$product->id}/price", ['sale_price' => '120.00'])->assertNoContent();
        Gate::define('PROMOTION_MANAGE', fn (User $user): bool => false);
        $this->getJson('/api/v1/promotions')->assertForbidden();
    }

    public function test_rounding_that_would_remove_the_discount_is_rejected_and_zero_price_is_an_explicit_offer(): void
    {
        $this->manager();
        $product = Product::factory()->create(['sale_price' => '0.01']);
        $values = $this->values($product);
        $this->createOffer([...$values, 'kind' => 'percentage', 'value' => '1.00'])->assertUnprocessable();
        $this->createOffer([...$values, 'value' => '0.00'])->assertCreated();
    }

    public function test_management_screen_and_product_options_reuse_permissions_and_exclude_internal_costs(): void
    {
        $this->withoutVite();
        $this->manager();
        $product = Product::factory()->create(['net_purchase_price' => '9876.54']);

        $this->get('/gestion/promotions')->assertOk()->assertSee('promotions-manage')->assertDontSee('9876.54');
        $this->getJson('/api/v1/promotions/products?search='.$product->sku)->assertOk()->assertJsonPath('data.0.id', (string) $product->id)
            ->assertJsonMissingPath('data.0.net_purchase_price');
        $this->getJson('/api/v1/promotions?per_page=101')->assertUnprocessable();
    }

    public function test_base_price_expected_value_and_audit_prevent_a_silent_overwrite(): void
    {
        $this->manager();
        Gate::define('PRODUCT_PRICE_UPDATE', fn (User $user): bool => true);
        $product = Product::factory()->create(['sale_price' => '100.00']);
        $url = "/api/v1/products/{$product->id}/price";
        $this->patchJson($url, ['sale_price' => '120.00', 'expected_sale_price' => '100.00'])->assertNoContent();
        $this->patchJson($url, ['sale_price' => '110.00', 'expected_sale_price' => '100.00'])->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => '120.00']);
        $this->assertSame(1, AuditEvent::where('result', 'SUCCESS')->whereIn('action', ['promotion.created', 'promotion.updated', 'pricing.base_price_updated'])->count());
    }

    private function values(Product $product): array
    {
        return ['product_id' => $product->id, 'title' => 'Offre test', 'kind' => 'fixed_price', 'value' => '80.00', 'starts_at' => '2026-11-05T09:00', 'ends_at' => '2026-11-05T10:00', 'timezone' => 'Africa/Casablanca', 'state' => 'enabled'];
    }

    private function createOffer(array $values): TestResponse
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/promotions', $values);
    }

    private function manager(): User
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        Gate::define('PROMOTION_MANAGE', fn (User $user): bool => $user->is($actor));

        return $actor;
    }
}
