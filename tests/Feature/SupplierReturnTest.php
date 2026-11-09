<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['SUPPLIER_MANAGE', 'SUPPLIER_RETURN_MANAGE', 'SUPPLIER_CREDIT_MANAGE'] as $p) {
            Gate::define($p, fn () => true);
        }
    }

    public function test_origin_dispatch_and_credit_have_separate_single_effects(): void
    {
        $actor = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $balance = StockBalance::factory()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'physical' => 10]);
        $this->actingAs($actor);
        $supplier = $this->postJson('/api/v1/supplier-returns/suppliers', ['code' => 'SUP-1', 'name' => 'Supplier', 'opening_balance' => '1000.00', 'justification' => 'Opening invoice'])->assertOk()->json('data');
        $purchase = $this->postJson('/api/v1/supplier-returns/purchases', ['supplier_id' => $supplier['id'], 'warehouse_id' => $warehouse->id, 'reference' => 'ACH-1', 'received_on' => '2026-11-01', 'lines' => [['product_id' => $product->id, 'quantity' => 10, 'net_unit_cost' => '25.00']]])->assertOk()->json('data');
        $line = DB::table('supplier_purchase_lines')->where('purchase_id', $purchase['id'])->first();
        $return = $this->postJson('/api/v1/supplier-returns', ['supplier_id' => $supplier['id'], 'purchase_id' => $purchase['id'], 'warehouse_id' => $warehouse->id, 'reason' => 'Defect', 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]]])->assertOk()->json('data');
        $this->assertSame(10, $balance->fresh()->physical);
        $this->assertSame(0, bccomp('1000.00', (string) DB::table('suppliers')->where('id', $supplier['id'])->value('current_balance'), 2));
        $this->postJson("/api/v1/supplier-returns/{$return['id']}/dispatch")->assertOk();
        $this->assertSame(8, $balance->fresh()->physical);
        $this->assertSame(0, bccomp('1000.00', (string) DB::table('suppliers')->where('id', $supplier['id'])->value('current_balance'), 2));
        $this->postJson("/api/v1/supplier-returns/{$return['id']}/dispatch")->assertOk();
        $this->assertSame(8, $balance->fresh()->physical);
        $key = (string) Str::uuid();
        $payload = ['decision' => 'accepted', 'amount' => '50.00', 'evidence' => 'AV-1'];
        $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/supplier-returns/{$return['id']}/credit", $payload)->assertOk();
        $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/supplier-returns/{$return['id']}/credit", $payload)->assertOk();
        $this->assertSame(0, bccomp('950.00', (string) DB::table('suppliers')->where('id', $supplier['id'])->value('current_balance'), 2));
        $this->assertSame(1, DB::table('supplier_balance_entries')->where('kind', 'CREDIT')->count());
        $this->get("/api/v1/supplier-returns/{$return['id']}/document")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
