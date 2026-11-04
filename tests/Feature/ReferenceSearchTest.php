<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductCrossReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReferenceSearchTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('hubCodes')]
    public function test_hub_codes_find_one_canonical_product_and_identify_the_matched_code(string $query, string $type, string $matched): void
    {
        $product = $this->hub();

        $this->getJson('/api/v1/catalogue/products?'.http_build_query(['reference' => $query]))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.original_reference', 'CR F 200028')
            ->assertJsonPath('data.0.matched_reference.type', $type)
            ->assertJsonPath('data.0.matched_reference.reference', $matched);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_cross_references', 5);
    }

    /** @return array<string, array{string, string, string}> */
    public static function hubCodes(): array
    {
        return [
            'original' => ['CR F 200028', 'ORIGINALE', 'CR F 200028'],
            'normalized original' => ['crf200028', 'ORIGINALE', 'CR F 200028'],
            'zero prefix' => ['0343012', 'CROISEE', '0343012'],
            'spaced reference' => ['03 434 3010 00', 'CROISEE', '03 434 3010 00'],
            'normalized spaced reference' => ['03434301000', 'CROISEE', '03 434 3010 00'],
            'different explicit code' => ['200028', 'CROISEE', '200028'],
            'dot' => ['566830.H195', 'CROISEE', '566830.H195'],
            'normalized case and space' => ['set1372', 'CROISEE', 'SET 1372'],
        ];
    }

    public function test_sleeve_original_and_different_explicit_cross_reference_find_the_same_product(): void
    {
        $product = Product::factory()->create(['publication' => 'published', 'original_reference' => 'H 208']);
        ProductCrossReference::factory()->for($product)->verified()->create(['reference' => 'CR H 208']);

        $this->getJson('/api/v1/catalogue/products?reference=h208')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->getJson('/api/v1/catalogue/products?reference=crh208')->assertOk()->assertJsonPath('data.0.id', $product->id);
    }

    public function test_literal_match_precedes_normalized_matches_and_shared_codes_keep_all_distinct_products(): void
    {
        $normalized = Product::factory()->create(['publication' => 'published', 'original_reference' => 'AB 01']);
        $literal = Product::factory()->create(['publication' => 'published', 'original_reference' => 'AB01']);
        ProductCrossReference::factory()->for($literal)->verified()->create(['reference' => 'AB 01']);
        ProductCrossReference::factory()->for($literal)->verified()->create(['reference' => 'ab01']);

        $this->getJson('/api/v1/catalogue/products?reference=AB01&per_page=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $literal->id)
            ->assertJsonPath('data.0.matched_reference.match', 'exact');
        $this->getJson('/api/v1/catalogue/products?reference=AB01&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $normalized->id)->assertJsonPath('data.0.matched_reference.match', 'normalized');
    }

    public function test_multiple_legacy_associations_never_duplicate_a_product_or_public_reference_display(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create(['publication' => 'published', 'original_reference' => 'AB01']);
        ProductCrossReference::factory()->for($product)->verified()->create(['reference' => 'AB01', 'brand' => 'Example']);
        ProductCrossReference::factory()->for($product)->verified()->create(['reference' => 'ab 01', 'brand' => 'Example']);

        $this->getJson('/api/v1/catalogue/products?reference=AB01')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/v1/catalogue/products/{$product->id}")->assertOk()->assertJsonCount(1, 'cross_references');
    }

    #[DataProvider('nonEquivalentCodes')]
    public function test_unknown_codes_do_not_infer_equivalences_or_create_data(string $query): void
    {
        $this->hub();

        $this->getJson('/api/v1/catalogue/products?'.http_build_query(['reference' => $query]))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_cross_references', 5);
    }

    /** @return array<string, array{string}> */
    public static function nonEquivalentCodes(): array
    {
        return [
            'missing leading zero' => ['343012'],
            'removed dot' => ['566830H195'],
            'replaced dot' => ['566830-H195'],
            'added separator' => ['CR-F200028'],
            'partial code' => ['2000'],
            'designation is not searched' => ['MOYEU DE ROUE 0343012'],
            'SQL wildcard is literal' => ['%'],
            'SQL injection is literal' => ["' OR 1=1 --"],
        ];
    }

    public function test_search_preserves_publication_verification_price_and_internal_field_visibility(): void
    {
        $published = Product::factory()->create(['publication' => 'published', 'sale_price' => '123.45', 'net_purchase_price' => '67.89']);
        ProductCrossReference::factory()->for($published)->verified()->create(['reference' => 'SHARED']);
        ProductCrossReference::factory()->for($published)->create(['reference' => 'UNVERIFIED']);
        Product::factory()->create(['original_reference' => 'SHARED']);
        Product::factory()->create(['publication' => 'archived', 'original_reference' => 'SHARED']);

        $url = '/api/v1/catalogue/products?reference=shared';
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $published->id)
            ->assertJsonMissingPath('data.0.sale_price')->assertJsonMissingPath('data.0.net_purchase_price')
            ->assertJsonMissingPath('data.0.original_reference_search_key')->assertJsonMissingPath('data.0.match_priority');
        $this->getJson('/api/v1/catalogue/products?reference=unverified')->assertOk()->assertJsonCount(0, 'data');
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user)->getJson($url)->assertOk()->assertJsonStructure(['data' => [['sale_price']]])
            ->assertJsonMissingPath('data.0.net_purchase_price');
    }

    public function test_search_combines_filters_and_keeps_a_product_without_an_inventory_record_searchable(): void
    {
        $product = Product::factory()->create(['publication' => 'published', 'category' => 'Moyeux', 'part_brand' => 'Example', 'original_reference' => 'CR F 200028']);

        $this->getJson('/api/v1/catalogue/products?reference=crf200028&category=Moyeux&part_brand=Example')
            ->assertOk()->assertJsonPath('data.0.id', $product->id)->assertJsonMissingPath('data.0.available_quantity');
        $this->getJson('/api/v1/catalogue/products?reference=crf200028&category=Autre')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_matched_code_is_escaped_in_html_and_empty_results_offer_contact(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create(['publication' => 'published']);
        $raw = '<script>alert("reference")</script>';
        ProductCrossReference::factory()->for($product)->verified()->create(['reference' => $raw]);

        $this->get('/catalogue?'.http_build_query(['reference' => $raw]))->assertOk()
            ->assertSee('Correspondance croisée')->assertSee($raw)->assertDontSee($raw, false);
        $this->get('/catalogue?reference=UNKNOWN')->assertOk()->assertSee('Aucun produit')->assertSee('Contactez l’équipe');
        $this->getJson('/api/v1/catalogue/products?reference=%20%20')->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    private function hub(): Product
    {
        $product = Product::factory()->create(['publication' => 'published', 'designation' => 'MOYEU DE ROUE 0343012', 'original_reference' => 'CR F 200028']);
        foreach (['0343012', '03 434 3010 00', '200028', '566830.H195', 'SET 1372'] as $reference) {
            ProductCrossReference::factory()->for($product)->verified()->create(['reference' => $reference, 'brand' => 'Example']);
        }

        return $product;
    }
}
