<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PricingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_basis_is_explicitly_configurable_without_inventing_tax_or_currency_conversion(): void
    {
        $actor = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '123.45']);
        $url = '/api/v1/promotions/settings';
        $this->putJson($url, ['tax_basis' => 'HT', 'expected_version' => 1])->assertUnauthorized();
        $this->actingAs($actor)->putJson($url, ['tax_basis' => 'HT', 'expected_version' => 1])->assertForbidden();
        Gate::define('SETTINGS_MANAGE', fn (User $user): bool => $user->is($actor));
        $this->getJson($url)->assertOk()->assertJsonPath('data.currency', 'MAD');
        $this->putJson($url, ['tax_basis' => 'HT', 'expected_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($url, ['tax_basis' => 'TTC', 'expected_version' => 1])->assertConflict();
        $this->putJson($url, ['tax_basis' => 'TTC', 'expected_version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->putJson($url, ['tax_basis' => 'HT', 'expected_version' => 3, 'currency' => 'EUR'])->assertUnprocessable();
        $this->actingAs($profile->user)->getJson("/api/v1/me/products/{$product->id}/price")->assertOk()->assertJsonPath('data.unit_price', '123.45')->assertJsonPath('data.currency', 'MAD')->assertJsonPath('data.tax_basis', 'TTC');
        $this->assertSame(2, AuditEvent::where('action', 'pricing.settings_updated')->where('result', 'SUCCESS')->count());
    }

    public function test_settings_and_audit_are_rolled_back_together(): void
    {
        $actor = User::factory()->create();
        Gate::define('SETTINGS_MANAGE', fn (User $user): bool => true);
        AuditEvent::creating(function (): void {
            throw new \RuntimeException('Simulated audit outage');
        });

        $this->actingAs($actor)->putJson('/api/v1/promotions/settings', ['tax_basis' => 'HT', 'expected_version' => 1])->assertStatus(500);
        $this->assertDatabaseHas('pricing_settings', ['id' => 1, 'tax_basis' => null, 'version' => 1]);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
