<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Resources\CustomerInvoiceResource;
use App\Services\InvoiceIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceIssuer $invoices) {}

    public function store(IssueInvoiceRequest $request): JsonResponse
    {
        $result = $this->invoices->issue($request->user(), $request->validated());

        return (new CustomerInvoiceResource($result['invoice']))->additional(['replayed' => $result['replayed']])->response()->setStatusCode($result['replayed'] ? 200 : 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $invoice): JsonResponse
    {
        return (new CustomerInvoiceResource($this->invoices->readable($request->user(), $invoice)))->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function document(Request $request, string $invoice): Response
    {
        $record = $this->invoices->readable($request->user(), $invoice);

        return response($this->invoices->document($record), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$record->number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
