<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCustomerDiscountRequest;
use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Services\CustomerPricing;
use App\Services\PriceAmount;
use App\Services\PromotionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerPricingController extends Controller
{
    public function __construct(private CustomerPricing $pricing, private PromotionManager $audit) {}

    public function show(Request $request, int $product): JsonResponse
    {
        Gate::authorize('catalogue-view-sale-price');
        $values = $request->validate(['previous_fingerprint' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-f0-9]{64}\z/D']]);
        abort_if(array_diff(array_keys($request->query()), ['previous_fingerprint']) !== [], 422, 'Champs de prix non autorisés.');
        $model = Product::query()->where('publication', 'published')->findOrFail($product);
        $price = $this->pricing->quotes(collect([$model]), $request->user())[$product];
        $changed = isset($values['previous_fingerprint']) ? ! hash_equals($values['previous_fingerprint'], $price['fingerprint']) : null;

        return response()->json(['data' => $price, 'changed' => $changed, 'message' => $changed ? 'Les conditions de prix ont changé. Vérifiez le nouveau tarif avant de poursuivre.' : null])->header('Cache-Control', 'private, no-store');
    }

    public function edit(): View
    {
        Gate::authorize('CUSTOMER_TERMS_MANAGE');

        return view('customer-discounts');
    }

    public function customers(Request $request): JsonResponse
    {
        Gate::authorize('CUSTOMER_TERMS_MANAGE');
        $values = $request->validate(['search' => 'sometimes|nullable|string|max:120', 'page' => 'sometimes|integer|min:1|max:2147483647']);
        $page = CustomerProfile::query()->with('user:id,name')->select(['id', 'user_id', 'customer_code', 'approval'])
            ->when(isset($values['search']), fn ($query) => $query->where(fn ($query) => $query->where('customer_code', $values['search'])->orWhereHas('user', fn ($query) => $query->where('name', 'like', '%'.addcslashes($values['search'], '%_\\').'%'))))
            ->orderBy('id')->paginate(20);
        $discounts = CustomerDiscount::query()->whereIn('customer_profile_id', $page->getCollection()->pluck('id'))->get()->keyBy('customer_profile_id');
        $page->through(fn (CustomerProfile $profile): array => [
            'id' => $profile->id, 'name' => $profile->user->name, 'customer_code' => $profile->customer_code, 'approval' => $profile->approval,
            'percentage' => $discounts->get($profile->id)?->percentage ?? '0.00', 'version' => $discounts->get($profile->id)?->version ?? 0,
        ]);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function updateDiscount(SaveCustomerDiscountRequest $request, CustomerProfile $customerProfile): JsonResponse
    {
        $values = $request->validated();
        $discount = DB::transaction(function () use ($request, $customerProfile, $values): CustomerDiscount {
            CustomerProfile::query()->lockForUpdate()->findOrFail($customerProfile->id);
            $existing = CustomerDiscount::query()->where('customer_profile_id', $customerProfile->id)->first();
            abort_if(($existing?->version ?? 0) !== (int) $values['expected_version'], 409, 'La remise client a changé. Actualisez la liste.');
            $before = $existing?->only(['customer_profile_id', 'percentage', 'version']);
            $discount = $existing ?? new CustomerDiscount;
            $discount->percentage = PriceAmount::decimal(PriceAmount::minorUnits($values['percentage']));
            if (! $discount->exists) {
                $discount->customer_profile_id = $customerProfile->id;
                $discount->created_by = $request->user()->id;
            } elseif ($discount->isDirty()) {
                $discount->version++;
            }
            if ($discount->isDirty()) {
                $discount->updated_by = $request->user()->id;
                $discount->save();
                $this->audit->record($request->user(), 'pricing.customer_discount_updated', $discount->id, $before, $discount->only(['customer_profile_id', 'percentage', 'version']));
            }

            return $discount;
        });

        return response()->json(['data' => $discount->only(['customer_profile_id', 'percentage', 'version'])])->header('Cache-Control', 'private, no-store');
    }
}
