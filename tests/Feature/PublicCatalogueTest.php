<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_published_products_are_public_and_prices_are_hidden(): void
    {
        $published = Product::factory()->create([
            'publication' => 'published', 'sale_price' => '25.00', 'net_purchase_price' => '10.00',
        ]);
        $draft = Product::factory()->create();
        $archived = Product::factory()->create(['publication' => 'archived']);
        $url = '/api/v1/catalogue/products';

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id);
        $this->getJson("$url/{$published->id}")->assertOk()
            ->assertJsonMissingPath('net_purchase_price')->assertJsonMissingPath('sale_price');
        $this->getJson("$url/{$draft->id}")->assertNotFound();
        $this->getJson("$url/{$archived->id}")->assertNotFound();
    }

    public function test_catalogue_filters_and_paginates_published_products(): void
    {
        $matching = Product::factory()->count(2)->create([
            'publication' => 'published', 'category' => 'Filtres', 'part_brand' => 'Example',
            'original_reference' => '000123',
        ]);
        foreach ($matching as $product) {
            DB::table('product_compatibilities')->insert([
                'product_id' => $product->id, 'vehicle_make' => 'Volvo', 'vehicle_model' => 'FH',
            ]);
        }
        Product::factory()->create(['publication' => 'published', 'original_reference' => '000123']);
        $url = '/api/v1/catalogue/products?category=Filtres&part_brand=Example&vehicle_make=Volvo&vehicle_model=FH&reference=000123&per_page=1';

        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $matching[0]->id);
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.id', $matching[1]->id);
        $this->getJson('/api/v1/catalogue/products?per_page=101')->assertUnprocessable();
    }

    public function test_public_pages_and_contact_submission(): void
    {
        $this->withoutVite();
        $published = Product::factory()->create(['publication' => 'published']);
        $draft = Product::factory()->create();
        $this->get('/')->assertOk()->assertSee($published->designation);
        $this->get('/catalogue?category=')->assertOk()->assertSee($published->designation);
        $this->get("/catalogue/{$draft->id}")->assertNotFound();
        $this->get("/catalogue/{$published->id}")->assertOk()->assertSee('Un compte approuvé');
        $this->get('/contact')->assertOk();
        $this->post('/contact', ['email' => 'invalid'])->assertSessionHasErrors(['name', 'email', 'message']);
        $this->followingRedirects()->post('/contact', [
            'name' => 'Test Visitor', 'email' => 'visitor@example.com', 'message' => 'Disponibilité du filtre',
        ])->assertOk()->assertSee('Votre demande a bien été reçue.');
        $this->assertDatabaseHas('contact_requests', ['email' => 'visitor@example.com']);
    }

    public function test_only_approved_price_access_reveals_the_sale_price(): void
    {
        $product = Product::factory()->create([
            'publication' => 'published', 'sale_price' => '25.00', 'net_purchase_price' => '10.00',
        ]);
        $approved = CustomerProfile::factory()->create(['approval' => 'APPROUVE'])->user;
        $url = "/api/v1/catalogue/products/{$product->id}";

        $this->actingAs(User::factory()->create())->getJson($url)->assertOk()
            ->assertJsonMissingPath('sale_price');
        $this->actingAs($approved)->getJson($url)->assertOk()
            ->assertJsonStructure(['sale_price'])->assertJsonMissingPath('net_purchase_price');
    }

    public function test_public_detail_shows_verified_references_and_login_link(): void
    {
        $this->withoutVite();
        Route::get('/test-login', fn () => 'Connexion')->name('login');
        Route::getRoutes()->refreshNameLookups();
        $product = Product::factory()->create(['publication' => 'published']);
        ProductCrossReference::factory()->for($product)->verified()->create(['brand' => 'Example', 'reference' => '000123-A']);
        ProductCrossReference::factory()->for($product)->create(['brand' => 'Example', 'reference' => '000123-B']);

        $this->get("/catalogue/{$product->id}")->assertOk()
            ->assertSee('000123-A')->assertDontSee('000123-B')
            ->assertSee('href="'.route('login').'"', false);
        $this->getJson('/api/v1/catalogue/products?reference=000123-A')->assertOk()
            ->assertJsonPath('data.0.id', $product->id);
        $this->getJson('/api/v1/catalogue/products?reference=000123-B')->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
