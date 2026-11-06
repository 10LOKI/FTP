<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductReferenceVersionRequest;
use App\Models\AuditEvent;
use App\Models\Product;
use App\Rules\DecimalPrice;
use App\Rules\ReferenceCode;
use App\Services\PriceAmount;
use App\Services\ProductReferenceManager;
use App\Services\PromotionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private ProductReferenceManager $references, private PromotionManager $promotions) {}

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

        DB::transaction(function () use ($request, $product, $validated): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $locked->net_purchase_price = $validated['net_purchase_price'];
            $locked->save();
            $this->auditProduct($request, $locked, 'product.purchase_price_updated', null, ['purchase_price_changed' => true]);
        });

        return response()->json(null, 204);
    }

    public function verifyReference(ProductReferenceVersionRequest $request, Product $product, int $reference): JsonResponse
    {
        $this->references->verify($product, $reference, (int) $request->validated('version'), $request->user());

        return response()->json(null, 204);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('PRODUCT_CREATE');

        $attributes = $this->attributes($request);
        $product = DB::transaction(function () use ($request, $attributes): Product {
            $product = Product::create($attributes);
            $this->auditProduct($request, $product, 'product.created', null);

            return $product;
        });

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_UPDATE');
        $attributes = $this->attributes($request);
        $product = DB::transaction(function () use ($product, $attributes, $request): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = $locked->only(self::AUDIT_FIELDS);
            if ($attributes['original_reference'] !== $locked->original_reference) {
                $locked = $this->references->updateOriginal($locked, $attributes['original_reference'], $locked->original_reference, $request->user());
            }
            unset($attributes['original_reference']);
            $locked->update($attributes);
            $this->auditProduct($request, $locked, 'product.updated', $before);

            return $locked;
        });

        return response()->json($product);
    }

    public function publication(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_PUBLISH');
        $validated = $request->validate(['publication' => 'required|in:draft,published,archived']);
        $product = DB::transaction(function () use ($request, $product, $validated): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = $locked->only(self::AUDIT_FIELDS);
            $locked->publication = $validated['publication'];
            $locked->save();
            $this->auditProduct($request, $locked, 'product.publication_changed', $before);

            return $locked;
        });

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
            'references.*.reference' => ['required', 'string', 'max:120', new ReferenceCode],
        ]);

        DB::transaction(function () use ($product, $validated, $request): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            foreach ([
                'barcodes' => ['product_barcodes', ['barcode']],
                'compatibilities' => ['product_compatibilities', ['vehicle_make', 'vehicle_model']],
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
            $this->references->replace($product, $validated['references'], $request->user());
            $this->auditProduct($request, $product, 'product.logistics_updated', null, [
                'barcodes_count' => count($validated['barcodes']), 'compatibilities_count' => count($validated['compatibilities']),
            ]);
        });

        return response()->json(null, 204);
    }

    public function price(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('PRODUCT_PRICE_UPDATE');
        $validated = $request->validate([
            'sale_price' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'expected_sale_price' => ['sometimes', 'nullable', new DecimalPrice],
        ]);

        DB::transaction(function () use ($product, $validated, $request): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            if (array_key_exists('expected_sale_price', $validated)) {
                $expected = $validated['expected_sale_price'] === null ? null : PriceAmount::minorUnits($validated['expected_sale_price']);
                $current = $locked->sale_price === null ? null : PriceAmount::minorUnits((string) $locked->sale_price);
                abort_if($expected !== $current, 409, 'Le tarif de base a changé. Actualisez le produit.');
            }
            $before = ['product_id' => (string) $locked->id, 'sale_price' => $locked->sale_price];
            $this->promotions->validateBasePriceChange($locked, (string) $validated['sale_price']);
            $locked->sale_price = $validated['sale_price'];
            if ($locked->isDirty('sale_price')) {
                $locked->save();
                $this->promotions->record($request->user(), 'pricing.base_price_updated', (string) $locked->id, $before, ['product_id' => (string) $locked->id, 'sale_price' => $locked->sale_price]);
            }
        });

        return response()->json(null, 204);
    }

    private const AUDIT_FIELDS = ['sku', 'designation', 'original_reference', 'publication', 'part_brand', 'category', 'hs_code', 'net_weight', 'weight_unit'];

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $extra
     */
    private function auditProduct(Request $request, Product $product, string $action, ?array $before, array $extra = []): void
    {
        AuditEvent::create([
            'actor_id' => $request->user()->id, 'request_id' => (string) Str::uuid(),
            'action' => $action, 'object_type' => 'product', 'object_id' => (string) $product->id,
            'result' => 'SUCCESS', 'changes' => ['before' => $before, 'after' => $extra ?: $product->only(self::AUDIT_FIELDS)],
            'occurred_at' => now('UTC'),
        ]);
    }

    private function attributes(Request $request): array
    {
        return $request->validate([
            'sku' => $request->isMethod('POST') ? 'required|string|max:255|unique:products,sku' : 'prohibited',
            'designation' => 'required|string|max:255',
            'original_reference' => ['required', 'string', 'max:255', new ReferenceCode],
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
