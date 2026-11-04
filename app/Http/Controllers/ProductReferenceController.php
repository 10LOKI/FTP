<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeOriginalReferenceRequest;
use App\Http\Requests\ProductReferenceVersionRequest;
use App\Http\Requests\StoreProductReferenceRequest;
use App\Http\Resources\ProductReferenceResource;
use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Services\ProductReferenceManager;
use App\Services\ProductReferenceNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductReferenceController extends Controller
{
    public function __construct(private ProductReferenceManager $references) {}

    public function edit(Product $product): View
    {
        Gate::authorize('REFERENCE_MANAGE');

        return view('product-references', [
            'product' => $product,
            'endpoints' => [
                'index' => route('product.references.index', $product),
                'store' => route('product.references.store', $product),
                'original' => route('product.references.original', $product),
                'update' => route('product.references.update', [$product, '__REFERENCE__']),
                'destroy' => route('product.references.destroy', [$product, '__REFERENCE__']),
                'verify' => route('product.references.verify', [$product, '__REFERENCE__']),
            ],
        ]);
    }

    public function index(Product $product): JsonResponse
    {
        Gate::authorize('REFERENCE_MANAGE');

        return response()->json(['data' => [
            'original_reference' => $this->original($product),
            'references' => ProductReferenceResource::collection(ProductCrossReference::query()
                ->where('product_id', $product->id)->orderBy('id')->get()),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreProductReferenceRequest $request, Product $product): JsonResponse
    {
        $reference = $this->references->store($product, $request->validated(), $request->user());

        return (new ProductReferenceResource($reference))->response()->setStatusCode($reference->wasRecentlyCreated ? 201 : 200);
    }

    public function update(StoreProductReferenceRequest $request, Product $product, int $reference): ProductReferenceResource
    {
        $values = $request->validated();

        return new ProductReferenceResource($this->references->update(
            $product, $reference, $values, (int) $values['version'], $request->user(),
        ));
    }

    public function destroy(ProductReferenceVersionRequest $request, Product $product, int $reference): JsonResponse
    {
        $this->references->delete($product, $reference, (int) $request->validated('version'), $request->user());

        return response()->json(null, 204);
    }

    public function updateOriginal(ChangeOriginalReferenceRequest $request, Product $product): JsonResponse
    {
        $values = $request->validated();
        $updated = $this->references->updateOriginal($product, $values['reference'], $values['expected_reference'], $request->user());

        return response()->json(['data' => $this->original($updated)]);
    }

    /** @return array{type: string, reference: string, normalized_reference: string} */
    private function original(Product $product): array
    {
        return [
            'type' => 'ORIGINALE', 'reference' => $product->original_reference,
            'normalized_reference' => ProductReferenceNormalizer::normalize($product->original_reference),
        ];
    }
}
