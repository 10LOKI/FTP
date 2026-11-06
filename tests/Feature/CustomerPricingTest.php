<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Models\Promotion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_offer_starts_exactly_at_the_boundary_and_expires_without_overwriting_the_base(): void
    {
        $this->withoutVite();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($profile->user);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        CustomerDiscount::factory()->for($profile)->create(['percentage' => '10.00']);
        Promotion::factory()->for($product)->enabled()->create(['kind' => 'fixed_price', 'value' => '80.00', 'starts_at' => '2026-11-05 08:00:00', 'ends_at' => '2026-11-05 09:00:00']);
        $url = "/api/v1/me/products/{$product->id}/price";
        $this->travelTo(CarbonImmutable::parse('2026-11-05T07:59:59Z'));
        $this->getJson($url)->assertOk()->assertJsonPath('data.unit_price', '90.00');
        $this->getJson('/api/v1/me/promotions')->assertOk()->assertJsonPath('total', 0);
        $this->travelTo(CarbonImmutable::parse('2026-11-05T08:00:00Z'));
        $earlierQuote = $this->getJson($url)->assertOk()->assertJsonPath('data.unit_price', '80.00')->assertJsonPath('data.applied_source', 'promotion')->json('data');
        $this->getJson('/api/v1/me/promotions')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.sale_price', '80.00');
        $this->get("/catalogue/{$product->id}")->assertOk()->assertSee('80.00');
        $this->travelTo(CarbonImmutable::parse('2026-11-05T09:00:00Z'));
        $this->getJson($url.'?previous_fingerprint='.$earlierQuote['fingerprint'])->assertOk()
            ->assertJsonPath('data.unit_price', '90.00')->assertJsonPath('changed', true)->assertJsonPath('data.promotion', null);
        $this->getJson('/api/v1/me/promotions')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame('80.00', $earlierQuote['unit_price']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sale_price' => '100.00']);
        $this->assertSame(0, AuditEvent::whereIn('action', ['promotion.created', 'promotion.updated'])->where('result', 'SUCCESS')->count());
    }

    public function test_real_customer_login_catalogue_price_and_logout_use_the_actual_approval_gate(): void
    {
        $this->freezeTime();
        $profile = CustomerProfile::factory()->for(User::factory()->create(['password' => 'Test-only-Passphrase-41']))->create(['approval' => 'APPROUVE', 'customer_code' => 'FTP18-TEST-CLIENT']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        Promotion::factory()->for($product)->enabled()->create(['value' => '20.00']);

        $login = $this->postJson('/api/v1/login', ['identifier' => 'FTP18-TEST-CLIENT', 'password' => 'Test-only-Passphrase-41'])->assertOk();
        $this->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->getJson("/api/v1/catalogue/products/{$product->id}")->assertOk()->assertJsonPath('sale_price', '80.00')->assertJsonPath('pricing.unit_price', '80.00');
        $logout = $this->postJson('/api/v1/logout')->assertNoContent();
        $this->withCookie(config('session.cookie'), $logout->getCookie(config('session.cookie'))->getValue());
        Auth::forgetGuards();
        $this->getJson("/api/v1/catalogue/products/{$product->id}")->assertOk()->assertJsonMissingPath('sale_price')->assertJsonMissingPath('pricing');
    }

    public function test_offer_title_is_escaped_and_the_promotions_page_handles_invalid_filters(): void
    {
        $this->withoutVite();
        $this->freezeTime();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        $title = '<script>alert("offer")</script>';
        Promotion::factory()->for($product)->enabled()->create(['title' => $title]);

        $this->actingAs($profile->user)->get('/promotions')->assertOk()->assertSee('Promotions en cours')->assertSee($title)->assertDontSee($title, false);
        $this->get('/promotions?per_page=101')->assertUnprocessable()->assertSee('Promotions en cours');
        $this->getJson('/api/v1/me/promotions')->assertOk()->assertJsonStructure(['refresh_after_ms']);
    }

    #[DataProvider('priceCases')]
    public function test_best_price_is_calculated_without_stacking_or_floating_point_drift(string $base, string $promotionValue, string $discount, string $expected, string $source): void
    {
        $this->freezeTime();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => $base]);
        CustomerDiscount::factory()->for($profile)->create(['percentage' => $discount]);
        Promotion::factory()->for($product)->enabled()->create(['value' => $promotionValue]);

        $this->actingAs($profile->user)->getJson("/api/v1/me/products/{$product->id}/price")->assertOk()
            ->assertJsonPath('data.unit_price', $expected)->assertJsonPath('data.applied_source', $source)
            ->assertJsonPath('data.base_unit_price', $base)->assertJsonPath('data.currency', 'MAD')->assertJsonPath('data.tax_basis', null);
    }

    public static function priceCases(): array
    {
        return [
            'promotion wins' => ['100.00', '20.00', '10.00', '80.00', 'promotion'],
            'customer wins' => ['100.00', '20.00', '30.00', '70.00', 'customer_discount'],
            'half cent rounds up' => ['0.05', '20.00', '50.00', '0.03', 'customer_discount'],
            'large amount stays exact' => ['9999999999.99', '0.01', '0.00', '9998999999.99', 'promotion'],
            'complete discount' => ['100.00', '10.00', '100.00', '0.00', 'customer_discount'],
        ];
    }

    public function test_anonymous_unapproved_and_internal_users_cannot_read_offers_or_customer_prices(): void
    {
        $product = Product::factory()->create(['publication' => 'published']);
        $this->getJson('/api/v1/me/promotions')->assertUnauthorized();
        $this->getJson("/api/v1/me/products/{$product->id}/price")->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/me/promotions')->assertForbidden();
        $profile = CustomerProfile::factory()->create();
        $this->actingAs($profile->user)->getJson('/api/v1/me/promotions')->assertForbidden();
        $this->getJson("/api/v1/catalogue/products/{$product->id}")->assertOk()->assertJsonMissingPath('pricing')->assertJsonMissingPath('sale_price');
    }

    public function test_only_current_enabled_published_offers_are_listed_and_other_customer_discounts_stay_private(): void
    {
        $this->freezeTime();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        CustomerDiscount::factory()->for($other)->create(['percentage' => '90.00']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        Promotion::factory()->for($product)->enabled()->create();
        Promotion::factory()->enabled()->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        Promotion::factory()->enabled()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        Promotion::factory()->for($product)->create();
        Promotion::factory()->for($product)->create(['state' => 'disabled']);
        $hidden = Product::factory()->create(['sale_price' => '100.00']);
        Promotion::factory()->for($hidden)->enabled()->create();
        ProductCrossReference::factory()->for($product)->verified()->create(['reference' => 'SAME-PART']);

        $this->actingAs($profile->user)->getJson('/api/v1/me/promotions?per_page=1')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $product->id)->assertJsonPath('data.0.pricing.customer_discount_percentage', '0.00')
            ->assertJsonMissingPath('data.0.net_purchase_price')->assertJsonMissingPath('data.0.pricing.created_by');
        $this->getJson('/api/v1/catalogue/products?reference=SAME-PART')->assertOk()->assertJsonPath('data.0.sale_price', '90.00');
        $this->getJson("/api/v1/me/products/{$hidden->id}/price")->assertNotFound();
        $this->getJson("/api/v1/me/products/{$product->id}/price?sale_price=1")->assertUnprocessable();
    }

    public function test_price_and_offer_data_are_removed_after_approval_is_revoked(): void
    {
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        $this->actingAs($profile->user)->getJson("/api/v1/me/products/{$product->id}/price")->assertOk();
        $profile->forceFill(['approval' => 'REFUSE'])->save();

        $this->getJson('/api/v1/catalogue/products')->assertOk()->assertJsonMissingPath('data.0.pricing')->assertJsonMissingPath('data.0.sale_price');
        $this->getJson('/api/v1/me/promotions')->assertForbidden();
    }

    public function test_missing_base_price_and_conflicting_legacy_offers_fail_closed(): void
    {
        $this->freezeTime();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => null]);
        $this->actingAs($profile->user)->getJson("/api/v1/me/products/{$product->id}/price")->assertOk()->assertJsonPath('data.status', 'missing_base_price')->assertJsonPath('data.unit_price', null);
        $product->forceFill(['sale_price' => '100.00'])->save();
        Promotion::factory()->count(2)->for($product)->enabled()->create();

        $this->getJson("/api/v1/me/products/{$product->id}/price")->assertOk()->assertJsonPath('data.status', 'conflicting_promotions')->assertJsonPath('data.unit_price', null);
    }
}
