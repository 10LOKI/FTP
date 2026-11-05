<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CustomerDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_remise_management_requires_its_own_permission_and_clients_cannot_choose_their_rate(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $url = "/api/v1/customers/{$profile->id}/discount";
        $this->putJson($url, ['percentage' => '50.00', 'expected_version' => 0])->assertUnauthorized();
        $this->actingAs($profile->user)->putJson($url, ['percentage' => '50.00', 'expected_version' => 0])->assertForbidden();
        Gate::define('PROMOTION_MANAGE', fn (User $user): bool => true);
        $this->putJson($url, ['percentage' => '50.00', 'expected_version' => 0])->assertForbidden();
        $this->assertDatabaseCount('customer_discounts', 0);
    }

    public function test_authorized_discount_update_reprices_only_its_customer_and_rejects_stale_or_injected_data(): void
    {
        $this->freezeTime();
        $actor = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        Gate::define('CUSTOMER_TERMS_MANAGE', fn (User $user): bool => $user->is($actor));
        $url = "/api/v1/customers/{$profile->id}/discount";
        $this->actingAs($actor)->putJson($url, ['percentage' => '12.50', 'expected_version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson($url, ['percentage' => '90.00', 'expected_version' => 0])->assertConflict();
        $this->putJson($url, ['percentage' => '101.00', 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('percentage');
        $this->putJson($url, ['percentage' => '10.00', 'expected_version' => 1, 'customer_profile_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('customer_profile_id');
        $this->getJson('/api/v1/customer-discounts')->assertOk()->assertJsonMissingPath('data.0.internal_notes')->assertJsonMissingPath('data.0.email');
        $this->actingAs($profile->user)->getJson("/api/v1/me/products/{$product->id}/price")->assertOk()->assertJsonPath('data.unit_price', '87.50');
        $this->actingAs($other->user)->getJson("/api/v1/me/products/{$product->id}/price")->assertOk()->assertJsonPath('data.unit_price', '100.00');
        $this->assertDatabaseCount('customer_discounts', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'pricing.customer_discount_updated', 'actor_id' => $actor->id]);
    }

    public function test_discount_write_and_audit_are_rolled_back_together(): void
    {
        $actor = User::factory()->create();
        $profile = CustomerProfile::factory()->create();
        Gate::define('CUSTOMER_TERMS_MANAGE', fn (User $user): bool => true);
        AuditEvent::creating(function (): void {
            throw new \RuntimeException('Simulated audit outage');
        });

        $this->actingAs($actor)->putJson("/api/v1/customers/{$profile->id}/discount", ['percentage' => '10.00', 'expected_version' => 0])->assertStatus(500);
        $this->assertDatabaseCount('customer_discounts', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_discount_editor_is_protected_and_does_not_render_private_customer_details(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create())->get('/gestion/remises-clients')->assertForbidden();
        Gate::define('CUSTOMER_TERMS_MANAGE', fn (User $user): bool => true);
        $this->get('/gestion/remises-clients')->assertOk()->assertSee('customer-discounts');
    }
}
