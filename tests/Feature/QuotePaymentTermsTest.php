<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerFinance;
use App\Services\SalesQuotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuotePaymentTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC'), 'receivables.enabled' => true,
            'quotes.issuance_enabled' => true, 'quotes.policy_version' => 'TEST-ONLY', 'quotes.number_prefix' => 'DEV-TEST-',
            'quotes.seller' => ['name' => 'Vendeur fictif', 'address' => 'Adresse fictive'], 'quotes.tax_note' => 'TTC : politique fictive de test']);
        DB::table('pricing_settings')->where('id', 1)->update(['currency' => 'MAD', 'tax_basis' => 'TTC']);
    }

    /** @return array{int, User, CustomerProfile} */
    private function scenario(): array
    {
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach (['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE', 'ORDER_CONFIRM', 'CUSTOMER_TERMS_MANAGE'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]));
        }
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        $order = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(), 'status' => 'SOUMISE', 'version' => 1,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_snapshot' => '{"company_name":"Client fictif"}',
            'contact' => '{"name":"Client fictif"}', 'delivery_address' => '{"address":"Rabat"}', 'billing_address' => '{"address":"Rabat"}',
            'lines' => json_encode([['id' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => 2, 'units_per_package' => 1]]),
            'catalogue_subtotal' => '200.00', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($actor)->postJson("/api/v1/sales/orders/$order/claim", ['expected_version' => 1])->assertOk();

        return [$order, $actor, $customer];
    }

    private function terms(User $actor, CustomerProfile $customer, string $mode = 'cash'): void
    {
        $version = DB::transaction(fn (): int => app(CustomerFinance::class)->state($customer->user_id)['version']);
        app(CustomerFinance::class)->operate($actor, $customer->user_id, 'terms', ['version' => $version,
            'request_key' => (string) Str::uuid(), 'mode' => $mode, 'label' => 'Conditions '.$mode, 'reason' => 'Conditions choisies par le responsable']);
    }

    /** @return array<string, mixed> */
    private function values(int $version = 2): array
    {
        return ['expected_version' => $version, 'valid_until' => now('UTC')->addWeek()->toDateString(),
            'payment_due_date' => now('UTC')->addWeeks(2)->toDateString(), 'fees' => '0.00'];
    }

    /** @return array<string, mixed> */
    private function payload(int $order, int $version = 2): array
    {
        $values = $this->values($version);
        $preview = $this->postJson("/api/v1/sales/orders/$order/quote-preview", $values)->assertOk()->json('data');

        return $values + ['preview_token' => $preview['preview_token'], 'request_key' => (string) Str::uuid()];
    }

    public function test_real_ftp28_terms_are_captured_with_source_version_and_explicit_proposed_date(): void
    {
        [$order, $actor, $customer] = $this->scenario();
        $this->terms($actor, $customer, 'postdated_cheque');
        $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $this->payload($order))->assertOk();
        $detail = $this->getJson("/api/v1/sales/orders/$order")->assertOk()->assertJsonPath('data.payment_mode', 'postdated_cheque')
            ->assertJsonPath('data.quote_drafts.0.snapshot.payment_terms_status', 'ready')->assertJsonPath('data.quote_drafts.0.issuance.ready', true)
            ->json('data.quote_drafts.0');
        $snapshot = $detail['snapshot'];
        $source = DB::table('customer_payment_terms')->where('customer_id', $customer->user_id)->first();
        $this->assertSame($source->id, $snapshot['payment_terms']['source_id']);
        $this->assertSame((int) $source->version, $snapshot['payment_terms']['version']);
        $this->assertSame('postdated_cheque', $snapshot['payment_terms']['mode']);
        $this->assertSame($this->values()['payment_due_date'], $snapshot['payment_terms']['due_date']);
        $this->assertSame('0.00', $snapshot['fees']);
        $this->assertDatabaseCount('customer_payment_terms', 1);
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_missing_mode_policy_or_date_never_invents_payment_conditions(): void
    {
        [$order, $actor, $customer] = $this->scenario();
        $this->postJson("/api/v1/sales/orders/$order/quote-preview", $this->values())->assertOk()
            ->assertJsonPath('data.snapshot.payment_terms_status', 'terms_required')->assertJsonPath('data.snapshot.payment_terms', null);
        $this->terms($actor, $customer);
        $withoutDate = $this->values();
        unset($withoutDate['payment_due_date']);
        $this->postJson("/api/v1/sales/orders/$order/quote-preview", $withoutDate)->assertOk()
            ->assertJsonPath('data.snapshot.payment_terms_status', 'due_date_required')->assertJsonPath('data.snapshot.payment_terms.due_date', null);
        config(['receivables.enabled' => false]);
        $this->postJson("/api/v1/sales/orders/$order/quote-preview", $this->values())->assertOk()
            ->assertJsonPath('data.snapshot.payment_terms_status', 'policy_disabled')->assertJsonPath('data.snapshot.payment_terms', null);
    }

    public function test_terms_change_rejects_stale_preview_and_unissued_draft_without_rewriting_it(): void
    {
        [$order, $actor, $customer] = $this->scenario();
        $this->terms($actor, $customer);
        $payload = $this->payload($order);
        $this->terms($actor, $customer, 'cheque');
        $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $payload)->assertConflict();
        $draft = $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $this->payload($order))->assertOk()->json('data.draft_id');
        $detail = $this->getJson("/api/v1/sales/orders/$order")->assertOk()->json('data.quote_drafts.0');
        $before = DB::table('sales_quote_drafts')->where('id', $draft)->value('snapshot');
        $this->terms($actor, $customer, 'postdated_cheque');
        $this->postJson("/api/v1/sales/orders/$order/quotes", ['draft_id' => $draft, 'expected_version' => 3,
            'request_key' => (string) Str::uuid(), 'issuance_token' => $detail['issuance']['token']])->assertConflict();
        $this->getJson("/api/v1/sales/orders/$order")->assertOk()->assertJsonPath('data.quote_drafts.0.issuance.ready', false);
        $this->assertSame($before, DB::table('sales_quote_drafts')->where('id', $draft)->value('snapshot'));
        $this->assertDatabaseCount('sales_quotes', 0);
    }

    public function test_issued_offer_and_agreement_keep_their_original_terms_after_account_changes(): void
    {
        [$order, $actor, $customer] = $this->scenario();
        $this->terms($actor, $customer);
        $draft = $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $this->payload($order))->assertOk()->json('data.draft_id');
        $detail = $this->getJson("/api/v1/sales/orders/$order")->assertOk()->json('data.quote_drafts.0');
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", ['draft_id' => $draft, 'expected_version' => 3,
            'request_key' => (string) Str::uuid(), 'issuance_token' => $detail['issuance']['token']])->assertOk()->json('data.quote_id');
        $pdf = $this->get("/api/v1/quotes/$id/document")->assertOk()->getContent();
        $this->terms($actor, $customer, 'cheque');
        $this->get("/api/v1/quotes/$id/document")->assertOk()->assertContent($pdf);
        $this->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", ['expected_version' => 4, 'request_key' => (string) Str::uuid(),
            'outcome' => 'ACCEPTED', 'contact_method' => 'PHONE', 'contact_name' => 'Client test', 'contacted_at' => now('UTC')->toIso8601String()])->assertOk();
        $accepted = app(SalesQuotes::class)->accepted($actor, $order);
        $this->assertSame('cash', $accepted['snapshot']['payment_terms']['mode']);
        $this->assertSame('Conditions cash', $accepted['snapshot']['payment_terms']['label']);
        $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $this->payload($order, 5))->assertOk();
        $this->getJson("/api/v1/sales/orders/$order")->assertOk()->assertJsonPath('data.quote_drafts.1.snapshot.payment_terms.mode', 'cheque');
        $this->assertDatabaseCount('stock_operations', 0);
    }

    public function test_customer_restriction_after_preview_prevents_issuance(): void
    {
        [$order, $actor, $customer] = $this->scenario();
        $this->terms($actor, $customer);
        $draft = $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $this->payload($order))->assertOk()->json('data.draft_id');
        $detail = $this->getJson("/api/v1/sales/orders/$order")->assertOk()->json('data.quote_drafts.0');
        $customer->forceFill(['approval' => 'REFUSE'])->save();
        $this->postJson("/api/v1/sales/orders/$order/quotes", ['draft_id' => $draft, 'expected_version' => 3,
            'request_key' => (string) Str::uuid(), 'issuance_token' => $detail['issuance']['token']])->assertConflict();
        $this->getJson("/api/v1/sales/orders/$order")->assertOk()->assertJsonPath('data.quote_drafts.0.issuance.ready', false);
        $this->assertDatabaseCount('sales_quotes', 0);
    }

    public function test_explicit_date_and_fee_validation_and_idempotency_do_not_allow_mode_override(): void
    {
        [$order, $actor, $customer] = $this->scenario();
        $this->terms($actor, $customer);
        $values = $this->values();
        $this->postJson("/api/v1/sales/orders/$order/quote-preview", $values + ['payment_terms' => ['mode' => 'cheque']])->assertUnprocessable();
        $this->postJson("/api/v1/sales/orders/$order/quote-preview", array_replace($values, ['payment_due_date' => now('UTC')->subDay()->toDateString()]))->assertUnprocessable();
        $this->postJson("/api/v1/sales/orders/$order/quote-preview", array_replace($values, ['fees' => '10.00']))->assertUnprocessable();
        $payload = $this->payload($order);
        $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $payload)->assertOk();
        $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson("/api/v1/sales/orders/$order/quote-drafts", array_replace($payload, ['payment_due_date' => now('UTC')->addWeeks(3)->toDateString()]))->assertConflict();
        $this->assertDatabaseCount('sales_quote_drafts', 1);
    }
}
