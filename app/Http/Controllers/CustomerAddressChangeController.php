<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideCustomerAddressChangeRequest;
use App\Http\Requests\StoreCustomerAddressChangeRequest;
use App\Http\Resources\CustomerAddressChangeResource;
use App\Models\AuditEvent;
use App\Models\CustomerAddressChange;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CustomerAddressChangeController extends Controller
{
    public function store(StoreCustomerAddressChangeRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            [$change, $created] = DB::transaction(function () use ($request, $data): array {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_if($user->must_change_password, 403, 'Change your temporary password first.');
                $profile = CustomerProfile::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                abort_unless($profile->approval === 'APPROUVE', 403, 'Customer approval is required.');
                $existing = CustomerAddressChange::whereKey($data['request_id'])->lockForUpdate()->first();
                if ($existing !== null) {
                    abort_unless($existing->customer_profile_id === $profile->id
                        && $existing->profile_version === (int) $data['version']
                        && $existing->address === $data['address'] && $existing->city === $data['city']
                        && $existing->country === $data['country'], 409, 'This request ID was already used.');

                    return [$existing, false];
                }
                abort_if($profile->version !== (int) $data['version'], 409, 'Your profile has changed. Refresh before submitting.');
                abort_if(CustomerAddressChange::where('active_customer_profile_id', $profile->id)->exists(),
                    409, 'An address change is already awaiting review.');
                $change = new CustomerAddressChange([
                    'customer_profile_id' => $profile->id, 'active_customer_profile_id' => $profile->id,
                    'profile_version' => $profile->version,
                    'address' => $data['address'], 'city' => $data['city'], 'country' => $data['country'],
                ]);
                $change->id = $data['request_id'];
                $change->save();
                $change->refresh();
                $this->audit($user, $change, 'customer.address_change.requested', null);

                return [$change, true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'Another address request was submitted. Refresh before retrying.');
        }

        return (new CustomerAddressChangeResource($change))->response()->setStatusCode($created ? 201 : 200);
    }

    public function ownIndex(Request $request): AnonymousResourceCollection
    {
        $profile = CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();

        return $this->listing($request, CustomerAddressChange::where('customer_profile_id', $profile->id));
    }

    public function staffIndex(Request $request): AnonymousResourceCollection
    {
        return $this->listing($request, CustomerAddressChange::where('status', 'PENDING'));
    }

    public function show(Request $request, CustomerAddressChange $change): JsonResponse
    {
        abort_if($request->query() !== [], 422, 'Address review does not accept filters.');
        $profile = CustomerProfile::findOrFail($change->customer_profile_id);

        return response()->json(['data' => [
            'change' => (new CustomerAddressChangeResource($change))->resolve($request),
            'customerId' => $profile->id,
            'current_profile' => $profile->only(['version', 'address', 'city', 'country']),
            'decision_reason' => $change->decision_reason,
        ]]);
    }

    public function decide(DecideCustomerAddressChangeRequest $request, CustomerAddressChange $change): CustomerAddressChangeResource
    {
        $data = $request->validated();
        $ownerId = CustomerProfile::findOrFail($change->customer_profile_id)->user_id;
        $updated = DB::transaction(function () use ($request, $change, $data, $ownerId): CustomerAddressChange {
            User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $profile = CustomerProfile::whereKey($change->customer_profile_id)->lockForUpdate()->firstOrFail();
            $current = CustomerAddressChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('CUSTOMER_APPROVE');
            abort_if($profile->user_id === $request->user()->id, 403, 'A different reviewer must decide your address change.');
            abort_if($current->status !== 'PENDING' || $current->version !== (int) $data['version']
                || $profile->version !== (int) $data['profile_version'],
                409, 'The request or profile has changed. Review the latest details.');
            if ($data['decision'] === 'APPROUVE') {
                abort_unless($profile->approval === 'APPROUVE', 409, 'The customer is no longer approved.');
                $profile->fill($current->only(['address', 'city', 'country']));
                $profile->version++;
                $profile->save();
            }
            $before = $current->status;
            $current->status = $data['decision'] === 'APPROUVE' ? 'APPROVED' : 'REFUSED';
            $current->active_customer_profile_id = null;
            $current->version++;
            $current->reviewed_by = $request->user()->id;
            $current->reviewed_at = now('UTC');
            $current->decision_reason = $data['reason'];
            $current->save();
            $this->audit($request->user(), $current, 'customer.address_change.decided', $before);

            return $current;
        }, 3);

        return new CustomerAddressChangeResource($updated);
    }

    /** @param Builder<CustomerAddressChange> $query */
    private function listing(Request $request, Builder $query): AnonymousResourceCollection
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page']) !== [], 422, 'Unsupported address-request filter.');

        return CustomerAddressChangeResource::collection($query->orderBy('created_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20))->withQueryString());
    }

    private function audit(User $actor, CustomerAddressChange $change, string $action, ?string $before): void
    {
        AuditEvent::create([
            'actor_id' => $actor->id, 'request_id' => (string) Str::uuid(),
            'action' => $action, 'object_type' => 'customer_address_change', 'object_id' => $change->id,
            'result' => 'SUCCESS', 'reason' => null,
            'changes' => ['before' => $before === null ? null : ['status' => $before],
                'after' => ['status' => $change->status, 'version' => $change->version, 'customer_profile_id' => $change->customer_profile_id]],
            'occurred_at' => now('UTC'),
        ]);
    }
}
