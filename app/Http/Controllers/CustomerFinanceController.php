<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerFinanceRequest;
use App\Models\CustomerProfile;
use App\Services\AdministratorAccess;
use App\Services\CustomerFinance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CustomerFinanceController extends Controller
{
    public function __construct(private readonly CustomerFinance $finance) {}

    public function page(Request $request): View
    {
        $own = CustomerProfile::where('user_id', $request->user()->id)->exists();
        if (! $own) {
            $this->finance->authorize($request->user());
        }

        return view('customer-finance', ['own' => $own]);
    }

    public function customers(Request $request): JsonResponse
    {
        $this->finance->authorize($request->user());
        $values = $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100']);
        $query = CustomerProfile::join('users', 'users.id', '=', 'customer_profiles.user_id');
        if (isset($values['search'])) {
            $query->where(function ($query) use ($values): void {
                $query->where('users.name', 'like', '%'.$values['search'].'%')->orWhere('customer_profiles.customer_code', 'like', '%'.$values['search'].'%');
            });
        }

        return response()->json($query->orderBy('users.id')->paginate(25, ['users.id', 'users.name', 'customer_profiles.customer_code', 'customer_profiles.approval']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, ?int $customer = null): JsonResponse
    {
        if ($customer === null) {
            CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();
            $customer = (int) $request->user()->id;
            $own = true;
        } else {
            $this->finance->authorize($request->user());
            $own = false;
        }
        $state = DB::transaction(fn (): array => $this->finance->state($customer), 3);
        $profile = CustomerProfile::where('user_id', $customer)->firstOrFail();
        $state['customer_name'] = $profile->user()->value('name');
        $state['customer_code'] = $profile->customer_code;
        if ($own) {
            // Reasons, bank references, actor identities and administrative block history stay private.
            unset($state['operations'], $state['version']);
        } else {
            $state['permissions'] = collect(['PAYMENT_RECORD', 'PAYMENT_CORRECT', 'PAYMENT_UNBLOCK', 'CUSTOMER_TERMS_MANAGE'])
                ->filter(fn (string $code): bool => $request->user()->can($code)
                    && ($code !== 'PAYMENT_UNBLOCK' || (app(AdministratorAccess::class)->isUsable($request->user())
                        && $request->user()->roles()->where('is_protected', true)->exists())))->values()->all();
            $state['reminders'] = DB::table('customer_finance_reminders')->join('customer_receivables', 'customer_receivables.id', '=', 'customer_finance_reminders.receivable_id')
                ->where('customer_receivables.customer_id', $customer)->pluck('customer_finance_reminders.receivable_id')->all();
        }

        return response()->json(['data' => $state])->header('Cache-Control', 'private, no-store');
    }

    public function mutate(CustomerFinanceRequest $request, int $customer, string $action): JsonResponse
    {
        $result = $this->finance->operate($request->user(), $customer, $action, $request->validated());

        return response()->json(['data' => $result], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }
}
