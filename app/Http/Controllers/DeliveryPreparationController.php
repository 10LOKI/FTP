<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeliveryPreparationRequest;
use App\Services\DeliveryPreparation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DeliveryPreparationController extends Controller
{
    public function __construct(private readonly DeliveryPreparation $deliveries) {}

    public function page(Request $request): View
    {
        $this->deliveries->authorize($request->user(), 'DELIVERY_PREPARE');

        return view('delivery-preparation');
    }

    public function ready(Request $request): JsonResponse
    {
        return response()->json($this->deliveries->ready($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->deliveries->batches($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function mutate(DeliveryPreparationRequest $request): JsonResponse
    {
        $action = $request->route('action');
        $batch = $request->route('batch');
        $data = match ($action) {
            'claim' => $this->deliveries->claim($request->user(), $request->validated()),
            'scan' => $this->deliveries->scan($request->user(), $batch, $request->validated(), (string) $request->header('Idempotency-Key')),
            'prepare' => $this->deliveries->validatePreparation($request->user(), $batch, $request->validated()),
            'transfer' => $this->deliveries->transfer($request->user(), $batch, $request->validated()),
            'dispatch' => $this->deliveries->dispatch($request->user(), $batch),
            default => abort(404),
        };

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function document(Request $request, string $batch): Response
    {
        $content = $this->deliveries->document($request->user(), $batch);

        return response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="bon-livraison.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
