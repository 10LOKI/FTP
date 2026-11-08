<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockMutationRequest;
use App\Http\Resources\StockBalanceResource;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\WarehouseStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WarehouseStockController extends Controller
{
    public function __construct(private WarehouseStock $stock) {}

    public function page(Request $request): View
    {
        $this->stock->authorize($request->user(), 'STOCK_VIEW');

        return view('warehouse-stock');
    }

    public function structure(Request $request): JsonResponse
    {
        $this->stock->authorize($request->user(), 'STOCK_VIEW');
        $values = $request->validate(['page' => 'sometimes|integer|min:1|max:2147483647']);
        $warehouses = Warehouse::query()->orderBy('code')->paginate(50, ['id', 'code', 'name', 'active', 'version'], 'page', $values['page'] ?? 1);

        return response()->json($warehouses)->header('Cache-Control', 'private, no-store');
    }

    public function locations(Request $request): JsonResponse
    {
        $this->stock->authorize($request->user(), 'STOCK_VIEW');
        $values = $request->validate(['warehouse_id' => 'required|uuid|exists:warehouses,id', 'page' => 'sometimes|integer|min:1|max:2147483647']);
        $page = DB::table('warehouse_locations')->where('warehouse_id', $values['warehouse_id'])->orderBy('code')->paginate(50, ['id', 'warehouse_id', 'code', 'aisle', 'shelf', 'level']);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function products(Request $request): JsonResponse
    {
        $this->stock->authorize($request->user(), 'STOCK_VIEW');
        $values = $request->validate(['search' => 'sometimes|nullable|string|max:120', 'page' => 'sometimes|integer|min:1|max:2147483647']);
        $page = Product::query()->select('id', 'sku', 'designation', 'original_reference')
            ->when(! empty($values['search']), fn ($q) => $q->where(fn ($q) => $q->where('sku', $values['search'])->orWhere('original_reference', $values['search'])->orWhere('designation', 'like', '%'.addcslashes($values['search'], '%_\\').'%')))
            ->orderBy('id')->paginate(20);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $this->stock->authorize($request->user(), 'STOCK_VIEW');
        $values = $request->validate(['warehouse_id' => 'sometimes|uuid|exists:warehouses,id', 'product_id' => 'sometimes|integer|exists:products,id', 'page' => 'sometimes|integer|min:1|max:2147483647']);
        $page = StockBalance::query()->with(['warehouse:id,code,name', 'product:id,sku,designation', 'location:id,code,aisle,shelf,level'])
            ->when(isset($values['warehouse_id']), fn ($q) => $q->where('warehouse_id', $values['warehouse_id']))
            ->when(isset($values['product_id']), fn ($q) => $q->where('product_id', $values['product_id']))
            ->orderBy('warehouse_id')->orderBy('product_id')->paginate(30);

        return StockBalanceResource::collection($page)->response()->header('Cache-Control', 'private, no-store');
    }

    public function movements(Request $request): JsonResponse
    {
        $this->stock->authorize($request->user(), 'STOCK_VIEW');
        $values = $request->validate(['balance_id' => 'required|uuid|exists:stock_balances,id', 'page' => 'sometimes|integer|min:1|max:2147483647']);
        $page = DB::table('stock_movements as m')->join('stock_operations as o', 'o.id', '=', 'm.operation_id')->where('m.balance_id', $values['balance_id'])
            ->orderByDesc('m.created_at')->orderByDesc('m.id')->paginate(30, ['m.id', 'm.operation_id', 'm.kind', 'm.reason', 'm.source_reference', 'm.physical_delta', 'm.reserved_delta', 'm.control_delta', 'm.rejected_delta', 'm.before', 'm.after', 'm.created_at', 'o.actor_id']);
        $page->through(function (object $row): object {
            $row->before = json_decode($row->before, true, flags: JSON_THROW_ON_ERROR);
            $row->after = json_decode($row->after, true, flags: JSON_THROW_ON_ERROR);

            return $row;
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->stock->authorize($request->user(), 'STOCK_VIEW');
        $values = $request->validate(['warehouse_id' => 'sometimes|uuid|exists:warehouses,id', 'page' => 'sometimes|integer|min:1|max:2147483647']);
        $page = DB::table('stock_alerts as a')->join('stock_balances as b', 'b.id', '=', 'a.balance_id')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->join('products as p', 'p.id', '=', 'b.product_id')
            ->whereNotNull('a.active_balance_id')->when(isset($values['warehouse_id']), fn ($q) => $q->where('b.warehouse_id', $values['warehouse_id']))
            ->orderByDesc('a.opened_at')->orderBy('a.id')->paginate(30, ['a.id', 'a.balance_id', 'a.basis', 'a.threshold', 'a.observed_quantity', 'a.opened_at', 'b.warehouse_id', 'b.product_id', 'w.code as warehouse_code', 'p.sku', 'p.designation']);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function mutate(StockMutationRequest $request, string $action): JsonResponse
    {
        return response()->json(['data' => $this->stock->execute($request->user(), $action, $request->validated(), $request->header('Idempotency-Key'))])->header('Cache-Control', 'private, no-store');
    }
}
