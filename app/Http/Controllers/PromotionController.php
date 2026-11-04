<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\PriceAmount;
use App\Services\PromotionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function __construct(private PromotionManager $manager) {}

    public function edit(): View
    {
        Gate::authorize('PROMOTION_MANAGE');

        return view('promotions-manage');
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('PROMOTION_MANAGE');
        $filters = $request->validate(['page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100', 'product_id' => 'sometimes|integer', 'state' => ['sometimes', Rule::in(['draft', 'enabled', 'disabled'])]]);
        $page = Promotion::query()->with('product:id,sku,designation')->when(isset($filters['product_id']), fn ($query) => $query->where('product_id', $filters['product_id']))
            ->when(isset($filters['state']), fn ($query) => $query->where('state', $filters['state']))
            ->orderByDesc('created_at')->orderBy('id')->paginate($filters['per_page'] ?? 20);

        return PromotionResource::collection($page)->response()->header('Cache-Control', 'private, no-store');
    }

    public function products(Request $request): JsonResponse
    {
        Gate::authorize('PROMOTION_MANAGE');
        $values = $request->validate(['search' => 'sometimes|nullable|string|max:120', 'page' => 'sometimes|integer|min:1|max:2147483647']);
        $products = Product::query()->select(['id', 'sku', 'designation', 'original_reference', 'sale_price', 'publication'])
            ->when(isset($values['search']), fn ($query) => $query->where(fn ($query) => $query->where('sku', $values['search'])->orWhere('original_reference', $values['search'])->orWhere('designation', 'like', '%'.addcslashes($values['search'], '%_\\').'%')))
            ->orderBy('id')->paginate(20);
        $products->through(fn (Product $product): array => ['id' => (string) $product->id, 'sku' => $product->sku, 'designation' => $product->designation, 'base_price' => $product->sale_price === null ? null : PriceAmount::decimal(PriceAmount::minorUnits((string) $product->sale_price)), 'publication' => $product->publication]);

        return response()->json($products)->header('Cache-Control', 'private, no-store');
    }

    public function store(SavePromotionRequest $request): JsonResponse
    {
        $promotion = $this->manager->store((int) $request->validated('product_id'), $request->promotionValues(), $request->header('Idempotency-Key'), $request->user());

        return (new PromotionResource($promotion))->response()->setStatusCode($promotion->wasRecentlyCreated ? 201 : 200);
    }

    public function update(SavePromotionRequest $request, Promotion $promotion): PromotionResource
    {
        return new PromotionResource($this->manager->update($promotion, $request->promotionValues(), (int) $request->validated('expected_version'), $request->user()));
    }

    public function settings(): JsonResponse
    {
        abort_unless(Gate::any(['PROMOTION_MANAGE', 'SETTINGS_MANAGE']), 403);

        return response()->json(['data' => DB::table('pricing_settings')->where('id', 1)->first(['currency', 'tax_basis', 'version']), 'timezone' => config('pricing.timezone')])->header('Cache-Control', 'private, no-store');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        Gate::authorize('SETTINGS_MANAGE');
        $values = $request->validate(['tax_basis' => ['present', 'nullable', Rule::in(['HT', 'TTC'])], 'expected_version' => 'required|integer|min:1|max:2147483647']);
        abort_if(array_diff(array_keys($request->all()), ['tax_basis', 'expected_version', '_token']) !== [], 422, 'Champs de configuration non autorisés.');
        DB::transaction(function () use ($request, $values): void {
            $settings = DB::table('pricing_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_if($settings->version !== (int) $values['expected_version'], 409, 'La configuration a changé. Actualisez.');
            if ($settings->tax_basis !== $values['tax_basis']) {
                DB::table('pricing_settings')->where('id', 1)->update(['tax_basis' => $values['tax_basis'], 'version' => $settings->version + 1, 'updated_by' => $request->user()->id, 'updated_at' => now('UTC')]);
                $this->manager->record($request->user(), 'pricing.settings_updated', '1', ['tax_basis' => $settings->tax_basis], ['tax_basis' => $values['tax_basis'], 'currency' => $settings->currency]);
            }
        });

        return response()->json(['data' => DB::table('pricing_settings')->where('id', 1)->first(['currency', 'tax_basis', 'version'])]);
    }
}
