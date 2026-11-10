<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Services\InvoiceIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\InvoiceFixtures;
use Tests\TestCase;

class InvoiceDeliveryIntegrationTest extends TestCase
{
    use InvoiceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTestBilling();
    }

    public function test_deferred_invoice_combines_real_partial_departures_without_touching_stock_or_orders(): void
    {
        [$actor, $customer, $order] = $this->preparedOrder();
        $first = $this->dispatchQuantity($actor, $order, 3);
        $this->assertDatabaseHas('customer_orders', ['id' => $order['order_id'], 'status' => 'PARTIELLEMENT_EXPEDIEE']);
        $second = $this->dispatchQuantity($actor, $order, 2);
        $this->assertDatabaseHas('customer_orders', ['id' => $order['order_id'], 'status' => 'EXPEDIEE']);
        $this->assertDatabaseHas('stock_balances', ['id' => $order['balance_id'], 'physical' => 5, 'reserved' => 0]);
        $this->assertSame(2, DB::table('stock_movements')->where('kind', 'dispatch')->count());
        $this->travel(15)->days();
        $before = $this->stockState();
        $input = $this->invoiceInput($customer, [$this->selection($first, 3), $this->selection($second, 2)]);
        $issued = $this->postJson('/api/v1/billing/invoices', $input)->assertCreated()->assertJsonPath('data.snapshot.total', '21.25')->json('data');
        $this->postJson('/api/v1/billing/invoices', $input)->assertOk()->assertJsonPath('data.id', $issued['id']);
        $this->assertSame($before, $this->stockState());
        $this->assertSame(5, (int) DB::table('billing_quantity_allocations')->where('kind', 'invoice')->sum('quantity'));
        $this->assertDatabaseCount('customer_invoices', 1);
        $this->assertSame(2, DB::table('billing_quantity_allocations')->count());
        $this->assertSame(0, $this->source($first)['remaining_quantity']);
        $this->assertSame(0, $this->source($second)['remaining_quantity']);
        $this->travelBack();
    }

    public function test_partial_invoice_followed_by_net_remainder_preserves_prior_return_and_stock(): void
    {
        [$actor, $customer, $order] = $this->preparedOrder();
        $batch = $this->dispatchQuantity($actor, $order, 5);
        $this->allocation($batch['line_id'], 'return_before_invoice', 2);
        $before = $this->stockState();
        $first = $this->invoiceInput($customer, [$this->selection($batch, 1)]);
        $invoice = $this->postJson('/api/v1/billing/invoices', $first)->assertCreated()->assertJsonPath('data.snapshot.total', '4.25')->json('data');
        $document = $this->get($invoice['document_url'])->assertOk()->getContent();
        $this->assertSame(2, $this->source($batch)['remaining_quantity']);
        $second = $this->invoiceInput($customer, [$this->selection($batch, 2)]);
        $this->postJson('/api/v1/billing/invoices', $second)->assertCreated()->assertJsonPath('data.snapshot.total', '8.50');
        $this->assertSame(0, $this->source($batch)['remaining_quantity']);
        $this->postJson('/api/v1/billing/invoices', $first)->assertOk()->assertJsonPath('data.id', $invoice['id']);
        $this->assertSame($document, $this->get($invoice['document_url'])->assertOk()->getContent());
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => [$this->selection($batch, 1)]])->assertConflict();
        $this->assertSame(3, (int) DB::table('billing_quantity_allocations')->where('kind', 'invoice')->sum('quantity'));
        $this->assertSame('12.75', bcadd(DB::table('customer_invoices')->orderBy('number')->first()->total, DB::table('customer_invoices')->orderByDesc('number')->first()->total, 2));
        $this->assertSame($before, $this->stockState());
    }

    public function test_return_recorded_after_preview_invalidates_the_emission_and_can_be_repreviewed(): void
    {
        [$actor, $customer, $order] = $this->preparedOrder();
        $batch = $this->dispatchQuantity($actor, $order, 5);
        $input = $this->invoiceInput($customer, [$this->selection($batch, 5)]);
        DB::transaction(function () use ($batch): void {
            DB::table('delivery_batches')->where('id', $batch['id'])->lockForUpdate()->firstOrFail();
            $this->allocation($batch['line_id'], 'return_before_invoice', 2);
        });
        $before = $this->stockState();
        $this->postJson('/api/v1/billing/invoices', $input)->assertConflict();
        $this->assertDatabaseCount('customer_invoices', 0);
        $this->assertDatabaseCount('invoice_sequences', 0);
        $fresh = $this->invoiceInput($customer, [$this->selection($batch, 3)]);
        $this->postJson('/api/v1/billing/invoices', $fresh)->assertCreated()->assertJsonPath('data.snapshot.total', '12.75');
        $this->assertSame($before, $this->stockState());
    }

    public function test_same_customer_with_different_historical_terms_cannot_be_combined(): void
    {
        [$actor, $customer, $order] = $this->preparedOrder();
        $first = $this->dispatchQuantity($actor, $order, 5);
        $otherOrder = $this->realDeliveryOrder($actor, $customer);
        $this->confirmBilling($otherOrder, $customer, 'Autres conditions de test');
        $second = $this->dispatchQuantity($actor, $otherOrder, 5);
        $selections = [$this->selection($first, 1), $this->selection($second, 1)];
        $this->postJson('/api/v1/billing/preview', ['customer_id' => $customer->user_id, 'lines' => $selections])->assertOk()->assertJsonPath('data.issuance.ready', false);
        $this->postJson('/api/v1/billing/invoices', ['customer_id' => $customer->user_id, 'lines' => $selections, 'issuance_token' => str_repeat('a', 64), 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('customer_invoices', 0);
        $this->assertDatabaseCount('billing_quantity_allocations', 0);
    }

    public function test_cross_customer_emission_is_rejected_and_read_permission_revocation_is_immediate(): void
    {
        [$actor, $customer, $order] = $this->preparedOrder();
        $batch = $this->dispatchQuantity($actor, $order, 5);
        $input = $this->invoiceInput($customer, [$this->selection($batch, 1)]);
        $otherCustomer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $wrong = $input;
        $wrong['customer_id'] = $otherCustomer->user_id;
        $this->postJson('/api/v1/billing/invoices', $wrong)->assertUnprocessable();
        $this->assertDatabaseCount('customer_invoices', 0);
        $invoice = $this->postJson('/api/v1/billing/invoices', $input)->assertCreated()->json('data');
        $this->get($invoice['document_url'])->assertOk();
        $actor->roles()->detach();
        $this->getJson('/api/v1/invoices/'.$invoice['id'])->assertForbidden();
        $this->get($invoice['document_url'])->assertForbidden();
        $this->actingAs($otherCustomer->user)->getJson('/api/v1/invoices/'.$invoice['id'])->assertNotFound();
        $this->get($invoice['document_url'])->assertNotFound();
        $this->actingAs($customer->user)->getJson('/api/v1/invoices/'.$invoice['id'])->assertOk();
    }

    public function test_issued_record_and_allocation_update_and_delete_are_rejected_by_storage(): void
    {
        [$actor, $customer, $order] = $this->preparedOrder();
        $batch = $this->dispatchQuantity($actor, $order, 5);
        $invoice = $this->postJson('/api/v1/billing/invoices', $this->invoiceInput($customer, [$this->selection($batch, 1)]))->assertCreated()->json('data');
        foreach (['customer_invoices', 'billing_quantity_allocations'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    if ($action === 'update') {
                        DB::table($table)->update($table === 'customer_invoices' ? ['number' => 'ALTERED'] : ['quantity' => 4]);
                    } else {
                        DB::table($table)->delete();
                    }
                    $this->fail($table.' must reject '.$action);
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('immutable', $exception->getMessage());
                }
            }
        }
        $this->assertDatabaseHas('customer_invoices', ['id' => $invoice['id'], 'number' => $invoice['number']]);
        $this->assertSame(1, (int) DB::table('billing_quantity_allocations')->sum('quantity'));
    }

    public function test_pdf_integrity_failure_prevents_download(): void
    {
        $record = (object) ['document_base64' => base64_encode('%PDF-corrupted'), 'document_sha256' => str_repeat('a', 64)];
        try {
            app(InvoiceIssuer::class)->document($record);
            $this->fail('A corrupted document must not be served.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }
    }

    private function preparedOrder(): array
    {
        $actor = $this->employee(['INVOICE_ISSUE', 'INVOICE_READ', 'ORDER_CONFIRM', 'DELIVERY_PREPARE', 'DELIVERY_DEPARTURE_CONFIRM']);
        $customer = CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        $order = $this->realDeliveryOrder($actor, $customer);
        $this->actingAs($actor);

        return [$actor, $customer, $order];
    }
}
