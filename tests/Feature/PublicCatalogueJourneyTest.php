<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicCatalogueJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_customer_login_reveals_prices_and_suspension_hides_them_on_the_next_request(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['password' => 'customer-password']);
        $profile = CustomerProfile::factory()->for($user)->create([
            'approval' => 'APPROUVE', 'customer_code' => 'PUBLIC-0001',
        ]);
        $product = Product::factory()->create([
            'publication' => 'published', 'sale_price' => '8123.45', 'net_purchase_price' => '7345.67',
        ]);

        $login = $this->postJson('/api/v1/login', [
            'identifier' => 'PUBLIC-0001', 'password' => 'customer-password',
        ])->assertOk();
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();

        $this->getJson("/api/v1/catalogue/products/{$product->id}")->assertOk()
            ->assertJsonStructure(['sale_price'])->assertJsonMissingPath('net_purchase_price')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/catalogue/products')->assertOk()
            ->assertJsonStructure(['data' => [['sale_price']]])->assertJsonMissingPath('data.0.net_purchase_price');
        $this->get("/catalogue/{$product->id}")->assertOk()->assertSee('8123.45')->assertDontSee('7345.67');
        $this->get('/')->assertOk()->assertSee('8123.45')->assertDontSee('7345.67');

        $profile->forceFill(['approval' => 'SUSPENDU'])->save();

        $this->getJson("/api/v1/catalogue/products/{$product->id}")->assertOk()->assertJsonMissingPath('sale_price');
        $this->getJson('/api/v1/catalogue/products')->assertOk()->assertJsonMissingPath('data.0.sale_price');
        $this->get("/catalogue/{$product->id}")->assertOk()->assertDontSee('8123.45')->assertDontSee('7345.67');
        $this->get('/catalogue')->assertOk()->assertDontSee('8123.45')->assertDontSee('7345.67');
    }

    #[DataProvider('customerStates')]
    public function test_price_gate_uses_the_persisted_customer_approval_state(string $approval, bool $allowed): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => $approval]);

        $this->assertSame($allowed, Gate::forUser($profile->user)->allows('catalogue-view-sale-price'));
    }

    /** @return array<string, array{string, bool}> */
    public static function customerStates(): array
    {
        return [
            'pending' => ['EN_ATTENTE', false],
            'refused' => ['REFUSE', false],
            'suspended' => ['SUSPENDU', false],
            'approved' => ['APPROUVE', true],
        ];
    }

    public function test_a_user_without_a_customer_profile_cannot_view_public_sale_prices(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '8123.45']);

        $this->assertFalse(Gate::forUser($user)->allows('catalogue-view-sale-price'));
        $this->actingAs($user)->getJson("/api/v1/catalogue/products/{$product->id}")
            ->assertOk()->assertJsonMissingPath('sale_price');
    }

    public function test_even_approved_customers_receive_404_for_unpublished_direct_urls(): void
    {
        $this->withoutVite();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $draft = Product::factory()->create();
        $archived = Product::factory()->create(['publication' => 'archived']);

        $this->actingAs($profile->user)->get("/catalogue/{$draft->id}")->assertNotFound();
        $this->get("/catalogue/{$archived->id}")->assertNotFound();
        $this->getJson("/api/v1/catalogue/products/{$archived->id}")->assertNotFound();
    }

    public function test_invalid_catalogue_filters_render_422_with_associated_errors_and_retained_values(): void
    {
        $this->withoutVite();
        $reference = str_repeat('x', 256);

        $this->get('/catalogue?'.http_build_query(['reference' => $reference, 'category' => 'Freins']))
            ->assertUnprocessable()->assertSee('aria-describedby="error-reference"', false)
            ->assertSee('value="Freins"', false)->assertSee($reference);
        $this->getJson('/api/v1/catalogue/products?category[]=Freins')
            ->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_truck_make_and_model_must_match_the_same_compatibility(): void
    {
        $product = Product::factory()->create(['publication' => 'published']);
        DB::table('product_compatibilities')->insert([
            ['product_id' => $product->id, 'vehicle_make' => 'Volvo', 'vehicle_model' => 'FH'],
            ['product_id' => $product->id, 'vehicle_make' => 'Renault', 'vehicle_model' => 'T'],
        ]);

        $this->getJson('/api/v1/catalogue/products?vehicle_make=Volvo&vehicle_model=T')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalogue/products?vehicle_make=Volvo&vehicle_model=FH')
            ->assertOk()->assertJsonPath('data.0.id', $product->id);
    }

    public function test_contact_errors_are_associated_and_invalid_contact_is_not_persisted(): void
    {
        $this->withoutVite();

        $response = $this->from('/contact')->post('/contact', [
            'name' => 'Visitor', 'email' => 'invalid', 'message' => 'Une référence à vérifier',
        ])->assertRedirect('/contact')->assertSessionHasErrors('email');
        $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
        $this->get('/contact')->assertOk()->assertSee('aria-describedby="contact-email-error"', false)
            ->assertSee('value="Visitor"', false)->assertSee('Une référence à vérifier');
        $this->assertDatabaseCount('contact_requests', 0);
    }

    public function test_product_content_is_escaped_and_public_logistics_are_displayed(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create([
            'publication' => 'published', 'designation' => '<script>alert("product")</script>',
            'hs_code' => '8708', 'net_weight' => '2.5000', 'weight_unit' => 'kg',
        ]);

        $this->get("/catalogue/{$product->id}")->assertOk()->assertSee($product->designation)
            ->assertDontSee($product->designation, false)->assertSee('Code SH 8708')->assertSee('Poids net 2.5000 kg');
        $this->get('/catalogue')->assertOk()->assertDontSee($product->designation, false);
    }

    public function test_home_uses_configured_company_content_and_only_published_brand_and_category_links(): void
    {
        $this->withoutVite();
        config(['app.company.name' => 'Société de test', 'app.company.services' => 'Service fourni par la société']);
        Product::factory()->create(['publication' => 'published', 'category' => 'Freins', 'part_brand' => 'Marque publique']);
        Product::factory()->create(['category' => 'Catégorie privée', 'part_brand' => 'Marque privée']);

        $this->get('/')->assertOk()->assertSee('Société de test')->assertSee('Service fourni par la société')
            ->assertSee('Freins')->assertSee('Marque publique')->assertDontSee('Catégorie privée')->assertDontSee('Marque privée');
    }

    public function test_buy_links_reach_real_customer_access_pages(): void
    {
        $this->withoutVite();
        $product = Product::factory()->create(['publication' => 'published']);

        $this->get("/catalogue/{$product->id}")->assertOk()->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false);
        $this->get('/connexion')->assertOk()->assertSee('Connexion client')->assertSee('/api/v1/login');
        $this->get('/demande-compte')->assertOk()->assertSee('Demander un compte client')->assertSee('/api/v1/customer-registrations');
    }
}
