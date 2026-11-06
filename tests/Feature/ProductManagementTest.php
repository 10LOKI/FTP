<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_requires_its_own_permission_and_creates_a_draft(): void
    {
        $payload = ['sku' => 'PART-001', 'designation' => 'Filter', 'original_reference' => '000123'];
        $this->postJson('/api/v1/products', $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/products', $payload)->assertForbidden();

        Gate::define('PRODUCT_CREATE', fn (User $user): bool => true);
        $this->postJson('/api/v1/products', $payload + ['publication' => 'published'])->assertCreated();
        $this->assertDatabaseHas('products', ['sku' => 'PART-001', 'publication' => 'draft']);
        $this->postJson('/api/v1/products', $payload)->assertUnprocessable();
    }

    public function test_description_permission_does_not_grant_publication_permission(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->create());
        Gate::define('PRODUCT_UPDATE', fn (User $user): bool => true);
        Gate::define('REFERENCE_MANAGE', fn (User $user): bool => true);

        $this->putJson("/api/v1/products/{$product->id}", [
            'designation' => 'Updated filter',
            'original_reference' => '000124',
            'publication' => 'published',
        ])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'publication' => 'draft']);

        $publicationUrl = "/api/v1/products/{$product->id}/publication";
        $this->patchJson($publicationUrl, ['publication' => 'published'])->assertForbidden();

        Gate::define('PRODUCT_PUBLISH', fn (User $user): bool => true);
        $this->patchJson($publicationUrl, ['publication' => 'published'])->assertOk();
        $this->patchJson($publicationUrl, ['publication' => 'archived'])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'publication' => 'archived']);
    }

    public function test_logistics_preserves_text_barcodes_and_rejects_reuse_on_another_product(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $this->actingAs(User::factory()->create());
        Gate::define('PRODUCT_UPDATE', fn (User $user): bool => true);
        Gate::define('REFERENCE_MANAGE', fn (User $user): bool => true);
        $payload = [
            'barcodes' => [['barcode' => '000123', 'units_per_package' => 6]],
            'compatibilities' => [['vehicle_make' => 'Volvo', 'vehicle_model' => 'FH']],
            'references' => [['brand' => 'Example', 'reference' => '000 123-A']],
        ];

        $this->putJson("/api/v1/products/{$product->id}/logistics", $payload)->assertNoContent();
        $this->putJson("/api/v1/products/{$product->id}/logistics", $payload)->assertNoContent();
        $this->assertDatabaseCount('product_barcodes', 1);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'barcode' => '000123']);
        $this->putJson("/api/v1/products/{$other->id}/logistics", $payload)->assertUnprocessable();
    }

    public function test_price_permission_is_independent_and_product_responses_hide_cost(): void
    {
        $product = Product::factory()->create();
        $product->net_purchase_price = '10.50';
        $product->save();
        $this->actingAs(User::factory()->create());
        Gate::define('PRODUCT_UPDATE', fn (User $user): bool => true);
        $url = "/api/v1/products/{$product->id}";

        $this->getJson($url)->assertOk()->assertJsonMissingPath('product.net_purchase_price');
        $this->getJson("$url/purchase-price")->assertForbidden();
        Gate::define('PURCHASE_PRICE_VIEW', fn (User $user): bool => true);
        $this->getJson("$url/purchase-price")->assertOk()->assertJsonPath('net_purchase_price', fn (mixed $price): bool => $price === '10.50' || $price === 10.5);
        $this->patchJson("$url/price", ['sale_price' => '22.00'])->assertForbidden();
        Gate::define('PRODUCT_PRICE_UPDATE', fn (User $user): bool => true);
        Gate::define('REFERENCE_MANAGE', fn (User $user): bool => true);
        $this->patchJson("$url/price", ['sale_price' => '22.00'])->assertNoContent();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => '22.00']);
        $this->putJson($url, ['designation' => 'Filter', 'original_reference' => '0001'])
            ->assertOk()
            ->assertJsonMissingPath('net_purchase_price')
            ->assertJsonMissingPath('sale_price');
    }

    public function test_reference_verification_requires_permission_and_matching_product(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $reference = DB::table('product_cross_references')->insertGetId([
            'product_id' => $product->id,
            'brand' => 'Example',
            'reference' => '000 123-A',
        ]);
        $this->actingAs(User::factory()->create());
        $url = "/api/v1/products/{$product->id}/references/$reference/verification";

        $this->patchJson($url)->assertForbidden();
        Gate::define('REFERENCE_MANAGE', fn (User $user): bool => true);
        $this->patchJson("/api/v1/products/{$other->id}/references/$reference/verification", ['version' => 1])->assertNotFound();
        $this->assertDatabaseHas('product_cross_references', ['id' => $reference, 'verified_at' => null]);
        $this->patchJson($url, ['version' => 1])->assertNoContent();
        $verifiedAt = DB::table('product_cross_references')->where('id', $reference)->value('verified_at');
        $this->assertNotNull($verifiedAt);
        $this->travel(1)->minute();
        $this->patchJson($url, ['version' => 1])->assertNoContent();
        $this->assertDatabaseHas('product_cross_references', ['id' => $reference, 'verified_at' => $verifiedAt]);
    }

    public function test_purchase_price_write_requires_purchase_record_permission(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(User::factory()->create());
        $url = "/api/v1/products/{$product->id}/purchase-price";
        Gate::define('PURCHASE_PRICE_VIEW', fn (User $user): bool => true);
        $this->patchJson($url, ['net_purchase_price' => '15.25'])->assertForbidden();

        Gate::define('PURCHASE_RECORD', fn (User $user): bool => true);
        $this->patchJson($url, ['net_purchase_price' => '-1'])->assertUnprocessable();
        $this->patchJson($url, ['net_purchase_price' => '15.25'])->assertNoContent();
        $this->getJson($url)->assertOk()->assertJsonPath('net_purchase_price', fn (mixed $price): bool => $price === '15.25' || $price === 15.25);
    }
}
