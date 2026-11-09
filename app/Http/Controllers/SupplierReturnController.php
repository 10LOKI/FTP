<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierReturnRequest;
use App\Services\SupplierReturns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SupplierReturnController extends Controller
{
    public function __construct(private readonly SupplierReturns $returns) {}

    public function page(Request $r): View
    {
        $this->returns->authorize($r->user(), 'SUPPLIER_RETURN_MANAGE');

        return view('supplier-returns');
    }

    public function index(Request $r): JsonResponse
    {
        return response()->json($this->returns->index($r->user()));
    }

    public function mutate(SupplierReturnRequest $r): JsonResponse
    {
        $a = $r->route('action');
        $id = $r->route('return');
        $data = match ($a) {
            'supplier' => $this->returns->createSupplier($r->user(), $r->validated()),
            'purchase' => $this->returns->createPurchase($r->user(), $r->validated()),
            'request' => $this->returns->request($r->user(), $r->validated()),
            'dispatch' => $this->returns->dispatch($r->user(), $id),
            'credit' => $this->returns->credit($r->user(), $id, $r->validated(), (string) $r->header('Idempotency-Key')),
            'adjustment' => $this->returns->adjustment($r->user(), $r->validated(), (string) $r->header('Idempotency-Key')),
            default => abort(404),
        };

        return response()->json(['data' => $data]);
    }

    public function document(Request $r, string $return): Response
    {
        return response($this->returns->document($r->user(), $return), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}
