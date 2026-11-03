<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function show(Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_UPDATE');

        return response()->json([
            'product' => $product,
            'barcodes' => DB::table('product_barcodes')->where('product_id', $product->id)
                ->orderBy('id')->get(['barcode', 'units_per_package']),
            'compatibilities' => DB::table('product_compatibilities')->where('product_id', $product->id)
                ->orderBy('id')->get(['vehicle_make', 'vehicle_model']),
            'references' => DB::table('product_cross_references')->where('product_id', $product->id)
                ->orderBy('id')->get(['id', 'brand', 'reference', 'verified_at']),
        ]);
    }

    public function purchasePrice(Product $product): JsonResponse
    {
        Gate::authorize('PURCHASE_PRICE_VIEW');

        return response()->json(['net_purchase_price' => $product->net_purchase_price]);
    }

    public function updatePurchasePrice(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PURCHASE_RECORD');
        $validated = $request->validate([
            'net_purchase_price' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
        ]);

        $product->net_purchase_price = $validated['net_purchase_price'];
        $product->save();

        return response()->json(null, 204);
    }

    public function verifyReference(Product $product, int $reference): JsonResponse
    {
        Gate::authorize('REFERENCE_MANAGE');

        DB::transaction(function () use ($product, $reference): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $association = DB::table('product_cross_references')
                ->where('product_id', $product->id)
                ->where('id', $reference);
            abort_unless($association->exists(), 404);
            $association->whereNull('verified_at')->update(['verified_at' => now()]);
        });

        return response()->json(null, 204);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('PRODUCT_CREATE');

        return response()->json(Product::create($this->attributes($request)), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_UPDATE');
        $product->update($this->attributes($request));

        return response()->json($product);
    }

    public function publication(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_PUBLISH');
        $validated = $request->validate(['publication' => 'required|in:draft,published,archived']);
        $product->publication = $validated['publication'];
        $product->save();

        return response()->json($product);
    }

    public function logistics(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_UPDATE');

        $validated = $request->validate([
            'barcodes' => 'present|array',
            'barcodes.*' => 'required|array:barcode,units_per_package',
            'barcodes.*.barcode' => ['required', 'string', 'max:255', 'distinct:ignore_case', Rule::unique('product_barcodes', 'barcode')->whereNot('product_id', $product->id)],
            'barcodes.*.units_per_package' => 'required|integer|min:1|max:2147483647',
            'compatibilities' => 'present|array',
            'compatibilities.*' => 'required|array:vehicle_make,vehicle_model',
            'compatibilities.*.vehicle_make' => 'required|string|max:100',
            'compatibilities.*.vehicle_model' => 'required|string|max:100',
            'references' => 'present|array',
            'references.*' => 'required|array:brand,reference',
            'references.*.brand' => 'required|string|max:100',
            'references.*.reference' => 'required|string|max:120',
        ]);

        DB::transaction(function () use ($product, $validated): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            foreach ([
                'barcodes' => ['product_barcodes', ['barcode']],
                'compatibilities' => ['product_compatibilities', ['vehicle_make', 'vehicle_model']],
                'references' => ['product_cross_references', ['brand', 'reference']],
            ] as $key => [$table, $identity]) {
                $existing = DB::table($table)->where('product_id', $product->id);
                $retainedIds = [];
                foreach ($validated[$key] as $values) {
                    $match = (clone $existing)->where(array_intersect_key($values, array_flip($identity)));
                    $id = $match->value('id');
                    if ($id === null) {
                        $id = DB::table($table)->insertGetId($values + ['product_id' => $product->id]);
                    } else {
                        $match->update($values);
                    }
                    $retainedIds[] = $id;
                }
                $existing->whereNotIn('id', $retainedIds)->delete();
            }
        });

        return response()->json(null, 204);
    }

    public function price(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_PRICE_UPDATE');
        $validated = $request->validate([
            'sale_price' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
        ]);

        $product->sale_price = $validated['sale_price'];
        $product->save();

        return response()->json(null, 204);
    }

    private function attributes(Request $request): array
    {
        return $request->validate([
            'sku' => $request->isMethod('POST') ? 'required|string|max:255|unique:products,sku' : 'prohibited',
            'designation' => 'required|string|max:255',
            'original_reference' => 'required|string|max:255',
            'part_brand' => 'sometimes|nullable|string|max:255',
            'category' => 'sometimes|nullable|string|max:255',
            'hs_code' => 'sometimes|nullable|string|max:255',
            'net_weight' => 'sometimes|nullable|numeric|min:0|max:99999999.9999|required_with:weight_unit',
            'weight_unit' => 'sometimes|nullable|string|max:12|required_with:net_weight',
            'images' => 'sometimes|nullable|array',
            'images.*' => 'required|url:http,https|max:2048',
        ]);
    }
}
