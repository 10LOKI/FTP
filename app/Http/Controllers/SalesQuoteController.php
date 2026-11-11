<?php

namespace App\Http\Controllers;

use App\Services\SalesQuotes;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SalesQuoteController extends Controller
{
    public function __construct(private readonly SalesQuotes $quotes) {}

    public function store(Request $request, int $order): JsonResponse
    {
        $input = $request->validate(['draft_id' => ['required', 'uuid'], 'request_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:4294967293'],
            'issuance_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);

        return response()->json(['data' => $this->quotes->issue($request->user(), $order, $input)])->header('Cache-Control', 'private, no-store');
    }

    public function document(Request $request, string $quote): Response
    {
        $record = $this->quotes->readable($request->user(), $quote);

        return response($this->quotes->document($record), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$record->number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function decide(Request $request, int $order, string $quote): JsonResponse
    {
        $input = $request->validate(['request_key' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1', 'max:4294967293'],
            'outcome' => ['required', Rule::in(['ACCEPTED', 'REFUSED'])],
            'contact_method' => ['required', Rule::in(['PHONE', 'EMAIL', 'IN_PERSON'])],
            'contact_name' => ['required', 'string', 'max:160'], 'contacted_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:2000']]);
        $input['contacted_at'] = CarbonImmutable::parse($input['contacted_at'])->utc()->format('Y-m-d H:i:s');

        return response()->json(['data' => $this->quotes->decide($request->user(), $order, $quote, $input)])->header('Cache-Control', 'private, no-store');
    }
}
