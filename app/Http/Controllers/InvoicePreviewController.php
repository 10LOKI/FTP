<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoicePreviewRequest;
use App\Http\Resources\InvoicePreviewResource;
use App\Services\InvoicePreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoicePreviewController extends Controller
{
    public function __construct(private readonly InvoicePreview $billing) {}

    public function page(Request $request): View
    {
        $this->billing->authorize($request->user());

        return view('invoice-preview');
    }

    public function index(Request $request): JsonResponse
    {
        $this->billing->authorize($request->user());
        $input = $request->validate(['customer_id' => ['required', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->billing->deliveries($request->user(), (int) $input['customer_id']))->header('Cache-Control', 'private, no-store');
    }

    public function preview(InvoicePreviewRequest $request): JsonResponse
    {
        return (new InvoicePreviewResource($this->billing->preview($request->user(), $request->validated())))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function source(Request $request, string $batch): JsonResponse
    {
        return response()->json(['data' => $this->billing->source($request->user(), $batch)])->header('Cache-Control', 'private, no-store');
    }
}
