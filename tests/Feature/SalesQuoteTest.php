<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\QuotePdf;
use App\Services\SalesQuotes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pricing.timezone' => env('FTP_TEST_PRICING_TIMEZONE', 'UTC')]);
        DB::table('pricing_settings')->where('id', 1)->update(['currency' => 'MAD', 'tax_basis' => 'TTC']);
    }

    /** @return array{int, User, CustomerProfile, string} */
    private function scenario(bool $readyFixture = true): array
    {
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $product = Product::factory()->create(['publication' => 'published', 'sale_price' => '100.00']);
        $order = DB::table('customer_orders')->insertGetId(['user_id' => $customer->user_id, 'number' => 'CMD-'.Str::ulid(), 'status' => 'SOUMISE', 'version' => 1,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'customer_snapshot' => '{"company_name":"Client test"}',
            'contact' => '{"name":"Client test"}', 'delivery_address' => '{"address":"Rabat"}', 'billing_address' => '{"address":"Rabat"}',
            'lines' => json_encode([['id' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => 2, 'units_per_package' => 1]]),
            'catalogue_subtotal' => '200.00', 'created_at' => now(), 'updated_at' => now()]);
        $actor = User::factory()->create();
        $role = Role::factory()->create();
        foreach (['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE', 'ORDER_CONFIRM'] as $code) {
            $role->permissions()->attach(Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]));
        }
        $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
        $this->actingAs($actor)->postJson("/api/v1/sales/orders/$order/claim", ['expected_version' => 1])->assertOk();
        $values = ['expected_version' => 2, 'valid_until' => now('UTC')->addWeek()->toDateString()];
        $preview = $this->postJson("/api/v1/sales/orders/$order/quote-preview", $values)->assertOk()->json('data');
        $draft = $this->postJson("/api/v1/sales/orders/$order/quote-drafts", $values + ['request_key' => (string) Str::uuid(), 'preview_token' => $preview['preview_token']])->assertOk()->json('data.draft_id');
        if ($readyFixture) {
            config(['quotes.issuance_enabled' => true, 'quotes.policy_version' => 'TEST-ONLY', 'quotes.number_prefix' => 'DEV-TEST-',
                'quotes.seller' => ['name' => 'Vendeur fictif', 'address' => 'Adresse de test'], 'quotes.tax_note' => 'Politique fictive de test TTC']);
            $snapshot = $preview['snapshot'];
            $snapshot['payment_terms_status'] = 'ready';
            $snapshot['payment_terms'] = ['mode' => 'test', 'label' => 'Conditions fictives de test'];
            $snapshot['fees'] = '0.00';
            DB::table('sales_quote_drafts')->where('id', $draft)->update(['snapshot' => json_encode($snapshot)]);
        }

        return [$order, $actor, $customer, $draft];
    }

    /** @return array<string, mixed> */
    private function issuance(int $order, string $draft): array
    {
        $record = DB::table('sales_quote_drafts')->where('id', $draft)->first();

        return ['draft_id' => $draft, 'expected_version' => 3, 'request_key' => (string) Str::uuid(),
            'issuance_token' => app(SalesQuotes::class)->readiness($record)['token'] ?? str_repeat('0', 64)];
    }

    /** @return array<string, mixed> */
    private function decision(): array
    {
        return ['expected_version' => 4, 'request_key' => (string) Str::uuid(), 'outcome' => 'ACCEPTED',
            'contact_method' => 'PHONE', 'contact_name' => 'Client test', 'contacted_at' => now('UTC')->toIso8601String(), 'note' => 'Note interne privée'];
    }

    public function test_real_missing_conditions_keep_issuance_disabled_without_writing_a_quote(): void
    {
        [$order, , , $draft] = $this->scenario(false);
        $input = $this->issuance($order, $draft);
        $this->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertStatus(503);
        config(['quotes.issuance_enabled' => true, 'quotes.policy_version' => 'TEST', 'quotes.number_prefix' => 'TEST-', 'quotes.tax_note' => 'TTC',
            'quotes.seller' => ['name' => 'Test', 'address' => 'Test']]);
        $this->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertConflict();
        $this->assertDatabaseCount('sales_quotes', 0);
    }

    public function test_issued_pdf_is_owned_private_historical_and_replay_safe(): void
    {
        [$order, $actor, $customer, $draft] = $this->scenario();
        $input = $this->issuance($order, $draft);
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertOk()->json('data.quote_id');
        $this->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertOk()->assertJsonPath('data.quote_id', $id)->assertJsonPath('data.replayed', true);
        $pdf = $this->get("/api/v1/quotes/$id/document")->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('DEVIS DEV-TEST-', $pdf);
        $this->assertStringNotContainsString('net_purchase_price', $pdf);
        Product::query()->update(['sale_price' => '500.00']);
        config(['quotes.seller.name' => 'Autre vendeur']);
        $this->actingAs($customer->user)->get("/api/v1/quotes/$id/document")->assertOk()->assertContent($pdf);
        $this->getJson("/api/v1/me/orders/$order")->assertOk()->assertJsonPath('data.quotes.0.id', $id);
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($other->user)->getJson("/api/v1/quotes/$id/document")->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/quotes/$id/document")->assertForbidden();
        $this->assertDatabaseCount('stock_operations', 0);
        $this->assertDatabaseHas('customer_orders', ['id' => $order, 'status' => 'ATTENTE_ACCORD', 'version' => 4]);
    }

    public function test_exact_agreement_is_attributed_idempotent_and_does_not_confirm_or_reserve(): void
    {
        [$order, $actor, , $draft] = $this->scenario();
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $this->issuance($order, $draft))->assertOk()->json('data.quote_id');
        $input = $this->decision();
        $url = "/api/v1/sales/orders/$order/quotes/$id/decision";
        $this->postJson($url, $input)->assertOk();
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($url, array_replace($input, ['outcome' => 'REFUSED']))->assertConflict();
        $accepted = app(SalesQuotes::class)->accepted($actor, $order);
        $this->assertSame($id, $accepted['quote_id']);
        $this->assertSame(1, $accepted['quote_version']);
        $this->assertSame('200.00', $accepted['snapshot']['estimated_subtotal']);
        $this->assertDatabaseHas('sales_quote_decisions', ['quote_id' => $id, 'actor_id' => $actor->id, 'contact_method' => 'PHONE']);
        $this->assertDatabaseHas('customer_orders', ['id' => $order, 'version' => 5, 'status' => 'ATTENTE_ACCORD']);
        $this->assertDatabaseCount('stock_operations', 0);
        $this->travel(8)->days();
        try {
            app(SalesQuotes::class)->accepted($actor, $order);
            $this->fail('Expired agreement should be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_changed_refused_or_expired_versions_cannot_gain_an_agreement(): void
    {
        [$order, , , $draft] = $this->scenario();
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $this->issuance($order, $draft))->assertOk()->json('data.quote_id');
        $this->postJson("/api/v1/sales/orders/$order/quotes/$draft/decision", $this->decision())->assertNotFound();
        $this->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", array_replace($this->decision(), ['contacted_at' => now('UTC')->subDay()->toIso8601String()]))->assertUnprocessable();
        $this->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", array_replace($this->decision(), ['outcome' => 'REFUSED']))->assertOk();
        $this->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", $this->decision())->assertConflict();
        $this->assertDatabaseHas('customer_orders', ['id' => $order, 'status' => 'EN_VERIFICATION']);
        $this->assertDatabaseCount('sales_quote_decisions', 1);
    }

    public function test_edits_invalidate_old_offer_and_expired_offer_rejects_decision(): void
    {
        [$order, , , $draft] = $this->scenario();
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $this->issuance($order, $draft))->assertOk()->json('data.quote_id');
        $this->travel(8)->days();
        $this->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", $this->decision())->assertConflict();
        $this->travelBack();
        $this->postJson("/api/v1/sales/orders/$order/revisions", ['expected_version' => 4, 'request_key' => (string) Str::uuid(),
            'kind' => 'contact', 'changes' => ['name' => 'Autre', 'email' => 'client@example.test', 'phone' => '000'], 'reason' => 'Correction'])->assertOk();
        $this->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", array_replace($this->decision(), ['expected_version' => 5]))->assertConflict();
        $this->assertDatabaseCount('sales_quote_decisions', 0);
    }

    public function test_audit_failure_rolls_back_document_and_order_state(): void
    {
        [$order, , , $draft] = $this->scenario();
        $input = $this->issuance($order, $draft);
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'sales.quote_issued') {
                throw new RuntimeException('Test audit failure');
            }
        });
        try {
            $this->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertServerError();
            $this->assertDatabaseCount('sales_quotes', 0);
            $this->assertDatabaseHas('customer_orders', ['id' => $order, 'version' => 3, 'status' => 'EN_VERIFICATION']);
        } finally {
            AuditEvent::flushEventListeners();
        }
    }

    public function test_other_staff_and_customers_cannot_issue_or_decide_and_changed_policy_rejects_issue(): void
    {
        [$order, $owner, $customer, $draft] = $this->scenario();
        $input = $this->issuance($order, $draft);
        $other = User::factory()->create();
        $other->roles()->attach($owner->roles()->first(), ['assigned_by' => $owner->id, 'assigned_at' => now()]);
        $this->actingAs($other)->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertForbidden();
        $this->actingAs($customer->user)->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertForbidden();
        $this->actingAs($owner);
        config(['quotes.tax_note' => 'Politique modifiée']);
        $this->postJson("/api/v1/sales/orders/$order/quotes", $input)->assertConflict();
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $this->issuance($order, $draft))->assertOk()->json('data.quote_id');
        $this->actingAs($other)->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", $this->decision())->assertForbidden();
        $this->actingAs($customer->user)->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", $this->decision())->assertForbidden();
        $this->assertDatabaseCount('sales_quote_decisions', 0);
    }

    public function test_decision_audit_failure_rolls_back_evidence_and_state(): void
    {
        [$order, , , $draft] = $this->scenario();
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $this->issuance($order, $draft))->assertOk()->json('data.quote_id');
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'sales.quote_accepted') {
                throw new RuntimeException('Simulated audit failure');
            }
        });
        try {
            $this->postJson("/api/v1/sales/orders/$order/quotes/$id/decision", $this->decision())->assertServerError();
            $this->assertDatabaseCount('sales_quote_decisions', 0);
            $this->assertDatabaseHas('customer_orders', ['id' => $order, 'version' => 4, 'status' => 'ATTENTE_ACCORD']);
        } finally {
            AuditEvent::flushEventListeners();
        }
    }

    public function test_issued_records_cannot_be_rewritten(): void
    {
        [$order, , , $draft] = $this->scenario();
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $this->issuance($order, $draft))->assertOk()->json('data.quote_id');
        $this->expectException(QueryException::class);
        DB::table('sales_quotes')->where('id', $id)->update(['number' => 'TAMPERED']);
    }

    public function test_pdf_paginates_and_rejects_unrepresentable_text_without_silent_corruption(): void
    {
        [$order, , , $draft] = $this->scenario();
        $id = $this->postJson("/api/v1/sales/orders/$order/quotes", $this->issuance($order, $draft))->assertOk()->json('data.quote_id');
        $quote = DB::table('sales_quotes')->where('id', $id)->first();
        $snapshot = json_decode($quote->snapshot, true);
        $snapshot['lines'] = array_fill(0, 45, array_replace($snapshot['lines'][0], ['designation' => str_repeat('Pièce (test) ', 18)]));
        $snapshot['estimated_subtotal'] = '9000.00';
        $document = app(QuotePdf::class)->render($snapshot);
        $this->assertMatchesRegularExpression('/\/Count [2-9][0-9]* /', $document);
        if (env('FTP_QUOTE_PDF_REVIEW_PATH')) {
            file_put_contents(env('FTP_QUOTE_PDF_REVIEW_PATH'), $document);
        }
        $snapshot['seller']['name'] = '漢字';
        $this->expectException(HttpException::class);
        app(QuotePdf::class)->render($snapshot);
    }
}
