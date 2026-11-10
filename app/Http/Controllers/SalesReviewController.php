<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerOrderResource;
use App\Models\CustomerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SalesReviewController extends Controller
{
    private function authorizeReview(Request $request): void
    {
        Gate::authorize('ORDER_REVIEW');
        abort_if(CustomerProfile::query()->where('user_id', $request->user()->id)->exists(), 403);
    }

    public function page(Request $request): Response
    {
        $this->authorizeReview($request);

        return response()->view('sales-review')->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeReview($request);
        $filters = $request->validate([
            'number' => ['nullable', 'string', 'max:40'],
            'customer_code' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['SOUMISE', 'EN_VERIFICATION', 'ATTENTE_ACCORD', 'CONFIRMEE', 'EN_PREPARATION', 'EXPEDIEE', 'LIVREE', 'ANNULEE', 'PARTIELLEMENT_LIVREE'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        $query = DB::table('customer_orders')->orderByDesc('id');
        foreach (['number', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['customer_code'])) {
            $query->where('customer_snapshot->customer_code', $filters['customer_code']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $page = $query->paginate(20)->through(function (object $order): array {
            $customer = json_decode($order->customer_snapshot, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => (string) $order->id, 'number' => $order->number, 'status' => $order->status,
                'version' => (int) $order->version, 'submitted_at' => $order->created_at,
                'customer' => Arr::only($customer, ['id', 'customer_code', 'type', 'company_name'])];
        });

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $this->authorizeReview($request);
        $row = DB::table('customer_orders')->where('id', $order)->first();
        abort_unless($row !== null, 404);
        $data = (new CustomerOrderResource($row))->resolve($request);
        $data['customer'] = Arr::only(json_decode($row->customer_snapshot, true, 512, JSON_THROW_ON_ERROR),
            ['id', 'customer_code', 'type', 'company_name']);
        $data['payment_mode'] = null;
        $data['payment_terms_status'] = 'contract_pending';

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
