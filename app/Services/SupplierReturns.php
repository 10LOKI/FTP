<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SupplierReturns
{
    public function __construct(private readonly WarehouseStock $stock, private readonly SupplierReturnPdf $pdf) {}

    public function authorize(User $actor, string $permission): void
    {
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize($permission);
    }

    public function index(User $actor): array
    {
        abort_unless(Gate::forUser($actor)->allows('SUPPLIER_RETURN_MANAGE') || Gate::forUser($actor)->allows('SUPPLIER_CREDIT_MANAGE'), 403);

        return ['suppliers' => DB::table('suppliers')->orderBy('code')->get(), 'purchases' => DB::table('supplier_purchases as p')->join('suppliers as s', 's.id', '=', 'p.supplier_id')->orderByDesc('p.received_on')->get(['p.*', 's.code as supplier_code']), 'returns' => DB::table('supplier_returns as r')->join('suppliers as s', 's.id', '=', 'r.supplier_id')->orderByDesc('r.created_at')->get(['r.*', 's.code as supplier_code'])];
    }

    /** @param array<string,mixed> $v */
    public function createSupplier(User $a, array $v): array
    {
        $this->authorize($a, 'SUPPLIER_MANAGE');

        return DB::transaction(function () use ($a, $v) {
            $id = (string) Str::uuid();
            DB::table('suppliers')->insert(['id' => $id, 'code' => $v['code'], 'name' => $v['name'], 'opening_balance' => $v['opening_balance'], 'current_balance' => $v['opening_balance'], 'created_at' => now(), 'updated_at' => now()]);
            $this->entry($a, $id, null, 'OPENING', $v['opening_balance'], $v['justification'], (string) Str::uuid());

            return (array) DB::table('suppliers')->find($id);
        });
    }

    /** @param array<string,mixed> $v */
    public function createPurchase(User $a, array $v): array
    {
        $this->authorize($a, 'SUPPLIER_MANAGE');

        return DB::transaction(function () use ($a, $v) {
            $id = (string) Str::uuid();
            DB::table('supplier_purchases')->insert(['id' => $id, 'supplier_id' => $v['supplier_id'], 'warehouse_id' => $v['warehouse_id'], 'reference' => $v['reference'], 'received_on' => $v['received_on'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($v['lines'] as $l) {
                DB::table('supplier_purchase_lines')->insert(['id' => (string) Str::uuid(), 'purchase_id' => $id, 'product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'net_unit_cost' => $l['net_unit_cost'], 'lot_reference' => $l['lot_reference'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            } $this->audit($a, 'supplier.purchase_recorded', $id);

            return ['id' => $id];
        });
    }

    /** @param array<string,mixed> $v */
    public function request(User $a, array $v): array
    {
        $this->authorize($a, 'SUPPLIER_RETURN_MANAGE');

        return DB::transaction(function () use ($a, $v) {
            $purchase = DB::table('supplier_purchases')->where('id', $v['purchase_id'])->lockForUpdate()->firstOrFail();
            abort_unless($purchase->supplier_id === $v['supplier_id'] && $purchase->warehouse_id === $v['warehouse_id'], 422, 'Origine achat, fournisseur ou dépôt incohérent.');
            $id = (string) Str::uuid();
            $amount = '0.00';
            foreach ($v['lines'] as $l) {
                $line = DB::table('supplier_purchase_lines')->where('id', $l['purchase_line_id'])->where('purchase_id', $purchase->id)->lockForUpdate()->first();
                abort_if(! $line || $l['quantity'] > $line->quantity - $line->returned_quantity, 422, 'Article hors achat ou quantité déjà retournée.');
                $amount = bcadd($amount, bcmul((string) $line->net_unit_cost, (string) $l['quantity'], 2), 2);
            } DB::table('supplier_returns')->insert(['id' => $id, 'number' => 'RF-'.Str::ulid(), 'supplier_id' => $purchase->supplier_id, 'purchase_id' => $purchase->id, 'warehouse_id' => $purchase->warehouse_id, 'reason' => $v['reason'], 'requested_amount' => $amount, 'stock_request_key' => (string) Str::uuid(), 'created_by' => $a->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($v['lines'] as $l) {
                $line = DB::table('supplier_purchase_lines')->find($l['purchase_line_id']);
                DB::table('supplier_return_lines')->insert(['id' => (string) Str::uuid(), 'return_id' => $id, 'purchase_line_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => $l['quantity'], 'net_unit_cost' => $line->net_unit_cost, 'created_at' => now(), 'updated_at' => now()]);
            } $this->audit($a, 'supplier.return_requested', $id);

            return (array) DB::table('supplier_returns')->find($id);
        });
    }

    public function dispatch(User $a, string $id): array
    {
        $this->authorize($a, 'SUPPLIER_RETURN_MANAGE');

        return DB::transaction(function () use ($a, $id) {
            $r = DB::table('supplier_returns')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'REQUESTED') {
                return (array) $r;
            } $lines = DB::table('supplier_return_lines')->where('return_id', $id)->get();
            $this->stock->dispatchSupplierReturn($a, $r->warehouse_id, $lines->map(fn ($l) => ['product_id' => (int) $l->product_id, 'quantity' => (int) $l->quantity])->all(), $r->stock_request_key, $r->number);
            foreach ($lines as $l) {
                DB::table('supplier_purchase_lines')->where('id', $l->purchase_line_id)->increment('returned_quantity', $l->quantity);
            } DB::table('supplier_returns')->where('id', $id)->update(['status' => 'DISPATCHED', 'dispatched_by' => $a->id, 'dispatched_at' => now('UTC'), 'version' => DB::raw('version+1'), 'updated_at' => now()]);
            $fresh = DB::table('supplier_returns')->find($id);
            $doc = $this->pdf->render($fresh, $lines);
            DB::table('supplier_returns')->where('id', $id)->update(['document_sha256' => hash('sha256', $doc)]);
            $this->audit($a, 'supplier.return_dispatched', $id);

            return (array) DB::table('supplier_returns')->find($id);
        });
    }

    /** @param array<string,mixed> $v */
    public function credit(User $a, string $id, array $v, string $key): array
    {
        $this->authorize($a, 'SUPPLIER_CREDIT_MANAGE');
        abort_unless(Str::isUuid($key), 422, 'Idempotency-Key UUID requis.');

        return DB::transaction(function () use ($a, $id, $v, $key) {
            $r = DB::table('supplier_returns')->where('id', $id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('supplier_balance_entries')->where('request_key', $key)->first();
            if ($existing) {
                return (array) $r;
            }abort_unless($r->status === 'DISPATCHED', 409, 'Le retour doit être expédié avant la décision d’avoir.');
            if ($v['decision'] === 'accepted') {
                abort_if(bccomp($v['amount'], $r->requested_amount, 2) > 0, 422, 'Avoir supérieur au retour.');
                DB::table('suppliers')->where('id', $r->supplier_id)->decrement('current_balance', $v['amount']);
                $this->entry($a, $r->supplier_id, $r->id, 'CREDIT', '-'.$v['amount'], $v['evidence'], $key);
                $status = 'CREDIT_ACCEPTED';
            } else {
                $status = 'CREDIT_REFUSED';
            } DB::table('supplier_returns')->where('id', $id)->update(['status' => $status, 'accepted_credit' => $v['decision'] === 'accepted' ? $v['amount'] : null, 'credit_evidence' => $v['evidence'], 'credit_decided_by' => $a->id, 'credit_decided_at' => now('UTC'), 'version' => DB::raw('version+1'), 'updated_at' => now()]);
            $this->audit($a, 'supplier.credit_decided', $id);

            return (array) DB::table('supplier_returns')->find($id);
        });
    }

    public function document(User $a, string $id): string
    {
        $this->authorize($a, 'SUPPLIER_RETURN_MANAGE');
        $r = DB::table('supplier_returns')->find($id);
        abort_unless($r && $r->dispatched_at, 409);
        $doc = $this->pdf->render($r, DB::table('supplier_return_lines')->where('return_id', $id)->get());
        abort_unless(hash_equals($r->document_sha256, hash('sha256', $doc)), 500);

        return $doc;
    }

    /** @param array<string,mixed> $v */
    public function adjustment(User $a, array $v, string $key): array
    {
        $this->authorize($a, 'SUPPLIER_CREDIT_MANAGE');
        abort_unless(Str::isUuid($key), 422, 'Idempotency-Key UUID requis.');

        return DB::transaction(function () use ($a, $v, $key): array {
            $supplier = DB::table('suppliers')->where('id', $v['supplier_id'])->lockForUpdate()->firstOrFail();
            if (DB::table('supplier_balance_entries')->where('request_key', $key)->exists()) {
                return (array) $supplier;
            }
            DB::table('suppliers')->where('id', $supplier->id)->increment('current_balance', $v['amount_delta']);
            $this->entry($a, $supplier->id, null, 'ADJUSTMENT', $v['amount_delta'], $v['justification'], $key);
            $this->audit($a, 'supplier.balance_adjusted', $supplier->id);

            return (array) DB::table('suppliers')->find($supplier->id);
        });
    }

    private function entry(User $a, string $supplier, ?string $return, string $kind, string $delta, string $why, string $key): void
    {
        DB::table('supplier_balance_entries')->insert(['id' => (string) Str::uuid(), 'supplier_id' => $supplier, 'return_id' => $return, 'kind' => $kind, 'amount_delta' => $delta, 'justification' => $why, 'actor_id' => $a->id, 'request_key' => $key, 'created_at' => now('UTC')]);
    }

    private function audit(User $a, string $action, string $id): void
    {
        AuditEvent::create(['actor_id' => $a->id, 'request_id' => (string) Str::uuid(), 'action' => $action, 'object_type' => 'supplier_return', 'object_id' => $id, 'result' => 'SUCCESS', 'occurred_at' => now('UTC')]);
    }
}
