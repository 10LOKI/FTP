<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitCustomerOrderRequest;
use App\Http\Resources\CustomerOrderResource;
use App\Services\CustomerOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerOrderController extends Controller
{
    public function __construct(private readonly CustomerOrderService $orders) {}

    public function page(): View
    {
        Gate::authorize('ORDER_SUBMIT_OWN');

        return view('customer-orders');
    }

    public function store(SubmitCustomerOrderRequest $request): JsonResponse
    {
        $result = $this->orders->submit((int) $request->user()->id, $request->validated(), $request->attributes->get('order_request_id'));

        return (new CustomerOrderResource($result['order']))->response()
            ->setStatusCode($result['replayed'] ? 200 : 201)
            ->header('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('ORDER_SUBMIT_OWN');

        return CustomerOrderResource::collection($this->orders->history((int) $request->user()->id));
    }

    public function show(Request $request, int $order): CustomerOrderResource
    {
        Gate::authorize('ORDER_SUBMIT_OWN');

        return new CustomerOrderResource($this->orders->owned((int) $request->user()->id, $order));
    }
}
