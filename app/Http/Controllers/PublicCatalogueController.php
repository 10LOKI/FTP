<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Rules\ReferenceCode;
use App\Services\CustomerPricing;
use App\Services\ProductReferenceNormalizer;
use App\Services\ProductReferenceSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicCatalogueController extends Controller
{
    public function __construct(private ProductReferenceSearch $referenceSearch, private CustomerPricing $pricing) {}

    private const PUBLIC_FIELDS = [
        'id', 'sku', 'designation', 'original_reference', 'part_brand', 'category',
        'hs_code', 'net_weight', 'weight_unit', 'images', 'publication',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->paginate($request)))->header('Cache-Control', 'private, no-store');
    }

    public function promotions(Request $request): JsonResponse
    {
        Gate::authorize('catalogue-view-sale-price');

        return response()->json($this->payload($this->paginate($request, true)))->header('Cache-Control', 'private, no-store');
    }

    public function promotionsPage(Request $request): View|Response
    {
        Gate::authorize('catalogue-view-sale-price');

        try {
            return view('public-catalogue', ['products' => $this->paginate($request, true), 'promotionsOnly' => true]);
        } catch (ValidationException $exception) {
            return response()->view('public-catalogue', [
                'products' => new LengthAwarePaginator([], 0, 20), 'promotionsOnly' => true,
                'errors' => (new ViewErrorBag)->put('default', $exception->validator->errors()),
            ], 422);
        }
    }

    public function catalogue(Request $request): View|Response
    {
        try {
            return view('public-catalogue', ['products' => $this->paginate($request)]);
        } catch (ValidationException $exception) {
            return response()->view('public-catalogue', [
                'products' => new LengthAwarePaginator([], 0, 20),
                'errors' => (new ViewErrorBag)->put('default', $exception->validator->errors()),
            ], 422);
        }
    }

    public function home(): View
    {
        $products = $this->publishedProducts()->latest('id')->limit(6)->get()->each->makeVisible('sale_price');
        if (Gate::allows('catalogue-view-sale-price')) {
            $this->pricing->annotate($products, request()->user());
        }

        return view('public-home', [
            'products' => $products,
            'categories' => Product::query()->where('publication', 'published')
                ->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category'),
            'brands' => Product::query()->where('publication', 'published')
                ->whereNotNull('part_brand')->where('part_brand', '!=', '')
                ->distinct()->orderBy('part_brand')->pluck('part_brand'),
        ]);
    }

    public function detail(int $product): View
    {
        return view('public-product', [
            'product' => $this->productDetail($product),
            'cartPackagings' => Gate::allows('CART_MANAGE_OWN')
                ? DB::table('product_barcodes')->where('product_id', $product)->distinct()->orderBy('units_per_package')->pluck('units_per_package')
                : collect(),
        ]);
    }

    private function paginate(Request $request, bool $promotionsOnly = false): LengthAwarePaginator
    {
        $filters = $request->validate([
            'category' => 'sometimes|nullable|string|max:255',
            'part_brand' => 'sometimes|nullable|string|max:255',
            'vehicle_make' => 'sometimes|nullable|string|max:100',
            'vehicle_model' => 'sometimes|nullable|string|max:100',
            'reference' => ['sometimes', 'nullable', 'string', 'max:255', new ReferenceCode],
            'page' => 'sometimes|required|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|required|integer|min:1|max:100',
        ]);

        $products = $this->publishedProducts();
        if ($promotionsOnly) {
            $at = now('UTC');
            $products->whereExists(fn (QueryBuilder $query) => $query->from('promotions')->whereColumn('product_id', 'products.id')
                ->where('state', 'enabled')->where('starts_at', '<=', $at)->where('ends_at', '>', $at));
        }
        foreach (['category', 'part_brand'] as $field) {
            if (isset($filters[$field])) {
                $products->where($field, $filters[$field]);
            }
        }

        $vehicle = array_intersect_key($filters, array_flip(['vehicle_make', 'vehicle_model']));
        if ($vehicle !== []) {
            $products->whereExists(function (QueryBuilder $query) use ($vehicle): void {
                $query->from('product_compatibilities')
                    ->whereColumn('product_id', 'products.id')
                    ->where($vehicle);
            });
        }

        if (isset($filters['reference'])) {
            $this->referenceSearch->apply($products, $filters['reference']);
        }

        $page = $products->orderBy('id')
            ->paginate($filters['per_page'] ?? 20)
            ->appends($filters)
            ->through(fn (Product $product): Product => $product->makeVisible('sale_price'));

        if (isset($filters['reference'])) {
            $this->referenceSearch->annotate($page->getCollection(), $filters['reference']);
        }

        if (Gate::allows('catalogue-view-sale-price')) {
            $this->pricing->annotate($page->getCollection(), $request->user());
        }

        return $page;
    }

    public function show(int $product): JsonResponse
    {
        return response()->json($this->productDetail($product))->header('Cache-Control', 'private, no-store');
    }

    private function productDetail(int $id): Product
    {
        $product = $this->publishedProducts()->findOrFail($id)->makeVisible('sale_price');

        $product->setAttribute('cross_references', DB::table('product_cross_references')
            ->where('product_id', $id)
            ->whereNotNull('verified_at')
            ->orderBy('brand')->orderBy('reference')->get(['brand', 'reference'])
            ->unique(fn (object $reference): string => $reference->brand.'|'.ProductReferenceNormalizer::searchKey($reference->reference))
            ->values());
        $product->setAttribute('compatibilities', DB::table('product_compatibilities')
            ->where('product_id', $id)
            ->orderBy('vehicle_make')->orderBy('vehicle_model')->get(['vehicle_make', 'vehicle_model']));

        if (Gate::allows('catalogue-view-sale-price')) {
            $this->pricing->annotate(collect([$product]), request()->user());
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function payload(LengthAwarePaginator $page): array
    {
        return $page->toArray() + (Gate::allows('catalogue-view-sale-price') ? ['refresh_after_ms' => $this->pricing->refreshAfterMs()] : []);
    }

    /** @return Builder<Product> */
    private function publishedProducts(): Builder
    {
        return Product::query()->select(self::PUBLIC_FIELDS)->where('publication', 'published')
            ->when(Gate::allows('catalogue-view-sale-price'), fn (Builder $query): Builder => $query->addSelect('sale_price'));
    }
}
