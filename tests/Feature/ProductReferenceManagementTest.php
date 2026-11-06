<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ProductReferenceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_is_closed_without_authentication_and_reference_permission(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create();
        $url = "/api/v1/products/{$product->id}/references";

        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, ['reference' => '0001'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->get("/gestion/produits/{$product->id}/references")->assertForbidden();
        $this->postJson($url, ['reference' => '0001'])->assertForbidden();
        $this->assertDatabaseCount('product_cross_references', 0);
    }

    public function test_a_blocked_customer_is_refused_even_if_a_permission_fixture_grants_access(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'REFUSE']);
        $product = Product::factory()->create();
        Gate::define('REFERENCE_MANAGE', fn (User $user): bool => true);

        $this->actingAs($profile->user)->getJson("/api/v1/products/{$product->id}/references")->assertForbidden();
    }

    public function test_adding_a_reference_preserves_raw_text_is_unverified_and_retry_does_not_duplicate_it(): void
    {
        $actor = $this->manager();
        $product = Product::factory()->create();
        $url = "/api/v1/products/{$product->id}/references";

        $response = $this->postJson($url, ['reference' => ' 000 ab.01 ', 'brand' => 'Example'])->assertCreated()
            ->assertJsonPath('data.reference', ' 000 ab.01 ')->assertJsonPath('data.normalized_reference', '000AB.01')
            ->assertJsonPath('data.verified_at', null)->assertJsonPath('data.version', 1);
        $this->postJson($url, ['reference' => '000AB.01', 'brand' => 'Example'])->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'))->assertJsonPath('data.reference', ' 000 ab.01 ');
        $this->assertDatabaseCount('product_cross_references', 1);
        $this->assertSame(1, AuditEvent::where('action', 'like', 'product.reference.%')->where('result', 'SUCCESS')->count());
        $this->assertDatabaseHas('audit_events', ['actor_id' => $actor->id, 'action' => 'product.reference.created']);
    }

    public function test_verification_is_idempotent_and_an_edit_requires_a_fresh_verification(): void
    {
        $this->manager();
        $product = Product::factory()->create(['publication' => 'published']);
        $reference = ProductCrossReference::factory()->for($product)->create(['reference' => '0001']);
        $url = "/api/v1/products/{$product->id}/references/{$reference->id}";

        $this->getJson('/api/v1/catalogue/products?reference=0001')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("$url/verification", ['version' => 1])->assertNoContent();
        $this->patchJson("$url/verification", ['version' => 1])->assertNoContent();
        $this->getJson('/api/v1/catalogue/products?reference=0001')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->putJson($url, ['reference' => '0002', 'brand' => $reference->brand, 'version' => 1])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.verified_at', null);
        $this->patchJson("$url/verification", ['version' => 1])->assertConflict();
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->getJson('/api/v1/catalogue/products?reference=0002')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("$url/verification", ['version' => 2])->assertNoContent();
        $this->getJson('/api/v1/catalogue/products?reference=0002')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->assertSame(2, AuditEvent::where('action', 'product.reference.verified')->count());
        $this->assertSame(1, AuditEvent::where('action', 'product.reference.updated')->count());
    }

    public function test_cross_product_identifiers_cannot_verify_edit_or_delete_an_association(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $reference = ProductCrossReference::factory()->create();
        $url = "/api/v1/products/{$product->id}/references/{$reference->id}";

        $this->patchJson("$url/verification", ['version' => 1])->assertNotFound();
        $this->putJson($url, ['reference' => '0001', 'version' => 1])->assertNotFound();
        $this->deleteJson($url, ['version' => 1])->assertNotFound();
        $this->assertModelExists($reference);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'product.reference.%')->where('result', 'SUCCESS')->count());
    }

    public function test_original_reference_update_preserves_raw_text_and_refuses_a_stale_value(): void
    {
        $this->manager();
        $product = Product::factory()->create(['publication' => 'published', 'original_reference' => 'OLD-001']);
        $url = "/api/v1/products/{$product->id}/original-reference";

        $this->putJson($url, ['reference' => ' 00 new.01 ', 'expected_reference' => 'OLD-001'])->assertOk()
            ->assertJsonPath('data.reference', ' 00 new.01 ')->assertJsonPath('data.normalized_reference', '00NEW.01');
        $this->putJson($url, ['reference' => 'OTHER', 'expected_reference' => 'OLD-001'])->assertConflict();
        $this->getJson('/api/v1/catalogue/products?reference=00new.01')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->getJson('/api/v1/catalogue/products?reference=OLD-001')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'original_reference' => ' 00 new.01 ']);
        $this->assertSame(1, AuditEvent::where('action', 'like', 'product.reference.%')->where('result', 'SUCCESS')->count());
    }

    public function test_invalid_and_injected_reference_fields_are_refused_without_writes(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $url = "/api/v1/products/{$product->id}/references";

        $this->postJson($url, ['reference' => '   '])->assertUnprocessable()
            ->assertJsonValidationErrors(['reference' => 'La référence doit contenir au moins un caractère autre qu’un espace.']);
        $this->postJson($url, ['reference' => '0001', 'verified_at' => now()->toIso8601String(), 'product_id' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors(['verified_at', 'product_id']);
        $this->postJson($url, ['reference' => str_repeat('x', 121)])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertDatabaseCount('product_cross_references', 0);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'product.reference.%')->where('result', 'SUCCESS')->count());
    }

    public function test_removal_affects_only_the_association_and_is_audited_without_product_prices(): void
    {
        $this->manager();
        $product = Product::factory()->create(['sale_price' => '123.45', 'net_purchase_price' => '67.89']);
        $reference = ProductCrossReference::factory()->for($product)->verified()->create();

        $this->deleteJson("/api/v1/products/{$product->id}/references/{$reference->id}", ['version' => 1])->assertNoContent();
        $this->assertModelMissing($reference);
        $this->assertModelExists($product);
        $event = AuditEvent::where('action', 'product.reference.deleted')->sole();
        $this->assertSame(null, $event->changes['after']);
        $this->assertArrayNotHasKey('sale_price', $event->changes['before']);
        $this->assertArrayNotHasKey('net_purchase_price', $event->changes['before']);
    }

    public function test_product_update_permission_cannot_bypass_reference_management(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('PRODUCT_UPDATE', fn (User $user): bool => true);
        $product = Product::factory()->create(['original_reference' => 'OLD']);

        $this->putJson("/api/v1/products/{$product->id}", ['designation' => 'Changed', 'original_reference' => 'NEW'])->assertForbidden();
        $this->putJson("/api/v1/products/{$product->id}/logistics", [
            'barcodes' => [['barcode' => '0001', 'units_per_package' => 1]],
            'compatibilities' => [], 'references' => [['brand' => 'Example', 'reference' => '0001']],
        ])->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'original_reference' => 'OLD']);
        $this->assertDatabaseCount('product_barcodes', 0);
        $this->assertDatabaseCount('product_cross_references', 0);
    }

    public function test_management_payloads_and_page_omit_purchase_cost_and_serve_the_react_editor(): void
    {
        $this->withoutVite();
        $this->manager();
        $product = Product::factory()->create(['net_purchase_price' => '9876.54']);
        ProductCrossReference::factory()->for($product)->create(['reference' => '0001']);

        $this->getJson("/api/v1/products/{$product->id}/references")->assertOk()
            ->assertJsonPath('data.references.0.reference', '0001')->assertJsonMissingPath('data.net_purchase_price');
        $this->get("/gestion/produits/{$product->id}/references")->assertOk()
            ->assertSee('product-references')->assertDontSee('9876.54');
    }

    public function test_duplicate_update_and_permission_revocation_preserve_the_existing_associations(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        $first = ProductCrossReference::factory()->for($product)->verified()->create(['reference' => '000A', 'brand' => 'Example']);
        $second = ProductCrossReference::factory()->for($product)->verified()->create(['reference' => '000B', 'brand' => 'Example']);
        $url = "/api/v1/products/{$product->id}/references/{$second->id}";

        $this->putJson($url, ['reference' => '000 a', 'brand' => 'Example', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        Gate::define('REFERENCE_MANAGE', fn (User $user): bool => false);
        $this->deleteJson($url, ['version' => 1])->assertForbidden();
        $this->assertModelExists($first);
        $this->assertDatabaseHas('product_cross_references', ['id' => $second->id, 'reference' => '000B', 'version' => 1]);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'product.reference.%')->where('result', 'SUCCESS')->count());
    }

    public function test_audit_failure_rolls_back_the_reference_and_its_search_keys(): void
    {
        $this->manager();
        $product = Product::factory()->create();
        AuditEvent::creating(function (): void {
            throw new \RuntimeException('Simulated audit storage failure');
        });

        $this->postJson("/api/v1/products/{$product->id}/references", ['reference' => '0001'])->assertStatus(500);
        $this->assertDatabaseCount('product_cross_references', 0);
        $this->assertSame(0, AuditEvent::where('action', 'like', 'product.reference.%')->where('result', 'SUCCESS')->count());
    }

    public function test_unicode_uppercase_expansion_and_optional_source_do_not_truncate_the_search_value(): void
    {
        $this->manager();
        $product = Product::factory()->create();

        $this->postJson("/api/v1/products/{$product->id}/references", ['reference' => str_repeat('ß', 120)])
            ->assertCreated()->assertJsonPath('data.normalized_reference', str_repeat('S', 240))->assertJsonPath('data.brand', '');
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::define('REFERENCE_MANAGE', fn (User $actor): bool => $actor->is($user));

        return $user;
    }
}
