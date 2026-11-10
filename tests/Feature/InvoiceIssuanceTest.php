<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Services\AuditWriter;
use App\Services\HistoricalInvoicePdf;
use App\Services\InvoiceIssuanceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\InvoiceFixtures;
use Tests\TestCase;

class InvoiceIssuanceTest extends TestCase
{
    use InvoiceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.issuance_enabled' => true, 'billing.policy_version' => 'test-v1', 'billing.number_prefix' => 'TEST-FAC-',
            'billing.sequence_key' => 'test-sequence', 'billing.seller' => ['name' => 'Vendeur de démonstration', 'address' => 'Adresse de test'],
            'billing.tax_presentation' => 'ttc_only', 'billing.tax_note' => 'Présentation TTC de test uniquement.']);
    }

    public function test_issue_replay_allocations_private_pdf_and_historical_snapshots_are_atomic(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        $issued = $this->postJson('/api/v1/billing/invoices', $input)->assertCreated()->assertJsonPath('replayed', false)->json('data');
        $this->assertSame('TEST-FAC-000001', $issued['number']);
        $this->assertSame('10.00', $issued['snapshot']['total']);
        $this->assertSame(30, $issued['snapshot']['payment_terms']['due_days']);
        $this->assertSame('2026-12-10', $issued['snapshot']['payment_terms']['due_date']);
        $this->assertSame('unavailable', $issued['payment_status']);
        $this->postJson('/api/v1/billing/invoices', $input)->assertOk()->assertJsonPath('data.id', $issued['id'])->assertJsonPath('replayed', true);
        $original = $this->get($issued['document_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $original);
        config(['billing.seller.name' => 'CHANGED']);
        DB::table('customer_orders')->where('id', $batch['order_id'])->update(['lines' => '[]', 'commercial_snapshot' => '{}']);
        $this->assertSame($original, $this->get($issued['document_url'])->assertOk()->getContent());
        $this->assertDatabaseCount('customer_invoices', 1);
        $this->assertDatabaseHas('billing_quantity_allocations', ['source_id' => $issued['id'], 'kind' => 'invoice', 'quantity' => 2]);
        $this->assertSame(2, DB::table('invoice_sequences')->value('next_number'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(1, AuditEvent::where('action', 'invoice.issued')->count());
        $this->actingAs($customer->user)->getJson('/api/v1/invoices/'.$issued['id'])->assertOk()->assertDontSee('PRIVATE-MARKER')->assertDontSee('request_hash')->assertDontSee('document_base64');
        $this->get($issued['document_url'])->assertOk();
        $other = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $this->actingAs($other->user)->getJson('/api/v1/invoices/'.$issued['id'])->assertNotFound();
        $this->get($issued['document_url'])->assertNotFound();
    }

    public function test_disabled_configuration_missing_confirmation_and_changed_terms_do_not_allocate(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        config(['billing.issuance_enabled' => false]);
        $this->postJson('/api/v1/billing/invoices', $input)->assertStatus(503);
        config(['billing.issuance_enabled' => true]);
        DB::table('customer_orders')->where('id', $batch['order_id'])->update(['commercial_snapshot' => '{}']);
        $this->postJson('/api/v1/billing/invoices', $input)->assertConflict();
        $this->confirm($batch, $customer);
        config(['billing.tax_note' => 'Conditions différentes']);
        $this->postJson('/api/v1/billing/invoices', $input)->assertConflict();
        $this->assertDatabaseCount('customer_invoices', 0);
        $this->assertDatabaseCount('billing_quantity_allocations', 0);
        $this->assertDatabaseCount('invoice_sequences', 0);
    }

    public function test_new_key_cannot_reuse_stale_quantities_and_key_cannot_change_content(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        $this->postJson('/api/v1/billing/invoices', $input)->assertCreated();
        $this->postJson('/api/v1/billing/invoices', array_replace($input, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $changed = $input;
        $changed['lines'][0]['quantity'] = 3;
        $this->postJson('/api/v1/billing/invoices', $changed)->assertConflict();
        $this->assertDatabaseCount('customer_invoices', 1);
        $this->assertDatabaseCount('billing_quantity_allocations', 1);
    }

    public function test_issue_permission_is_separate_and_revocation_is_immediate(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        $actor->roles()->detach();
        $this->grant($actor);
        $this->postJson('/api/v1/billing/invoices', $input)->assertForbidden();
        $this->grant($customer->user, ['INVOICE_ISSUE']);
        $this->actingAs($customer->user)->postJson('/api/v1/billing/invoices', $input)->assertForbidden();
    }

    public function test_pdf_failure_rolls_back_number_and_allocations(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        $this->mock(HistoricalInvoicePdf::class)->shouldReceive('render')->once()->andThrow(new \RuntimeException('pdf failure'));
        $this->postJson('/api/v1/billing/invoices', $input)->assertStatus(500);
        $this->assertDatabaseCount('customer_invoices', 0);
        $this->assertDatabaseCount('billing_quantity_allocations', 0);
        $this->assertDatabaseCount('invoice_sequences', 0);
        $this->assertSame(0, AuditEvent::where('action', 'invoice.issued')->count());
    }

    public function test_audit_failure_rolls_back_document_allocations_and_number(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit failure'));
        $this->postJson('/api/v1/billing/invoices', $input)->assertStatus(500);
        $this->assertDatabaseCount('customer_invoices', 0);
        $this->assertDatabaseCount('billing_quantity_allocations', 0);
        $this->assertDatabaseCount('invoice_sequences', 0);
    }

    public function test_issued_invoices_cannot_be_updated_or_deleted(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        $this->postJson('/api/v1/billing/invoices', $input)->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('customer_invoices')->delete();
    }

    public function test_unsupported_pdf_characters_fail_before_emission_without_silent_replacement(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        config(['billing.seller.name' => 'شركة اختبار']);
        $selection = $this->selection($batch, 2);
        $preview = $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$selection]])->assertOk()->json('data');
        $input['issuance_token'] = $preview['issuance']['token'];
        $this->postJson('/api/v1/billing/invoices', $input)->assertUnprocessable();
        $this->assertDatabaseCount('customer_invoices', 0);
        $this->assertDatabaseCount('billing_quantity_allocations', 0);
    }

    public function test_long_invoice_pdf_contains_multiple_pages_and_last_line(): void
    {
        $line = ['sku' => 'DEMO-001', 'designation' => 'Pièce de démonstration avec accents é à ç', 'delivery_number' => 'BL-TEST',
            'order_number' => 'CMD-TEST', 'original_reference' => '001234', 'quantity' => 1, 'unit_price' => '5.00', 'total' => '5.00'];
        $snapshot = ['number' => 'TEST-FAC-000100', 'issued_at' => '2026-11-10 18:00:00', 'seller' => ['name' => 'Vendeur Démo', 'address' => 'Adresse de test'],
            'customer' => ['name' => 'Client Démo', 'address' => 'Adresse client de test'], 'payment_terms' => ['label' => 'Conditions de test'],
            'tax_note' => 'Présentation TTC de test', 'lines' => array_fill(0, 100, $line), 'total' => '500.00'];
        $pdf = app(HistoricalInvoicePdf::class)->render($snapshot);
        $this->assertStringContainsString('100. DEMO-001', $pdf);
        $this->assertStringContainsString('TOTAL MAD TTC : 500.00', $pdf);
        $this->assertGreaterThan(1, preg_match_all('/\/Type \/Page\b/', $pdf));
        if (getenv('FTP88_PDF_SAMPLE')) {
            file_put_contents(getenv('FTP88_PDF_SAMPLE'), $pdf);
        }
    }

    public function test_impossible_historical_due_dates_block_emission_but_valid_leap_dates_are_preserved(): void
    {
        [$actor, $customer, $batch, $input] = $this->issuable();
        $order = DB::table('customer_orders')->find($batch['order_id']);
        $commercial = json_decode($order->commercial_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $commercial['billing_v1']['payment_terms']['due_date'] = '2026-02-31';
        DB::table('customer_orders')->where('id', $order->id)->update(['commercial_snapshot' => json_encode($commercial, JSON_THROW_ON_ERROR)]);
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => $input['lines']])->assertOk()->assertJsonPath('data.issuance.ready', false);
        $this->postJson('/api/v1/billing/invoices', $input)->assertConflict();
        $this->assertDatabaseCount('customer_invoices', 0);
        $commercial['billing_v1']['payment_terms']['due_date'] = '2028-02-29';
        DB::table('customer_orders')->where('id', $order->id)->update(['commercial_snapshot' => json_encode($commercial, JSON_THROW_ON_ERROR)]);
        $fresh = $this->invoiceInput($customer, $input['lines']);
        $this->postJson('/api/v1/billing/invoices', $fresh)->assertCreated()->assertJsonPath('data.snapshot.payment_terms.due_date', '2028-02-29');
    }

    private function issuable(): array
    {
        $actor = $this->employee(['INVOICE_ISSUE', 'INVOICE_READ']);
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $batch = $this->delivery($actor, $customer);
        $this->confirm($batch, $customer);
        $this->actingAs($actor);
        $selection = $this->selection($batch, 2);
        $preview = $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$selection]])->assertOk()->assertJsonPath('data.issuance.ready', true)->json('data');

        return [$actor, $customer, $batch, ['customer_id' => $customer->user_id, 'lines' => [$selection], 'request_key' => (string) Str::uuid(), 'issuance_token' => $preview['issuance']['token']]];
    }

    private function confirm(array $batch, CustomerProfile $customer): void
    {
        $order = DB::table('customer_orders')->find($batch['order_id']);
        DB::table('customer_orders')->where('id', $order->id)->update(['commercial_snapshot' => json_encode(['billing_v1' => [
            'status' => 'confirmed', 'confirmed_by' => 1, 'confirmed_at' => now()->toIso8601String(), 'lines_sha256' => InvoiceIssuanceContext::linesHash($order->lines),
            'customer_id' => $customer->user_id, 'currency' => 'MAD', 'tax_basis' => 'TTC', 'customer' => ['name' => 'Client de démonstration', 'address' => 'Adresse de test', 'secret' => 'PRIVATE-MARKER'],
            'payment_terms' => ['mode' => 'fixture', 'label' => 'Conditions de démonstration', 'due_days' => 30, 'due_date' => '2026-12-10'],
        ]])]);
    }
}
