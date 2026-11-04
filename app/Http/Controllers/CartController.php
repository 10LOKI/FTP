<?php

namespace App\Http\Controllers;

use App\Http\Resources\CartResource;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function page(): View
    {
        Gate::authorize('CART_MANAGE_OWN');

        return view('customer-cart');
    }

    public function show(Request $request): CartResource
    {
        Gate::authorize('CART_MANAGE_OWN');

        return new CartResource($this->carts->snapshot((int) $request->user()->id));
    }

    public function putLine(Request $request, int $product): CartResource
    {
        Gate::authorize('CART_MANAGE_OWN');
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'units_per_package' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'expected_version' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'user_id' => ['prohibited'], 'customer_id' => ['prohibited'], 'cart_id' => ['prohibited'],
            'price' => ['prohibited'], 'sale_price' => ['prohibited'], 'net_purchase_price' => ['prohibited'],
            'total' => ['prohibited'], 'reserved_quantity' => ['prohibited'],
        ]);

        return new CartResource($this->carts->putLine(
            (int) $request->user()->id, $product, (int) $validated['quantity'],
            (int) $validated['units_per_package'], (int) $validated['expected_version'],
            $request->attributes->get('cart_request_id'),
        ));
    }

    public function destroyLine(Request $request, int $line): CartResource
    {
        Gate::authorize('CART_MANAGE_OWN');
        $validated = $request->validate(['expected_version' => ['required', 'integer', 'min:0', 'max:4294967294']]);

        return new CartResource($this->carts->removeLine((int) $request->user()->id, $line, (int) $validated['expected_version'], $request->attributes->get('cart_request_id')));
    }
}
