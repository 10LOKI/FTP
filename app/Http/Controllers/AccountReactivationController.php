<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideReactivationRequest;
use App\Http\Requests\StoreReactivationRequest;
use App\Http\Resources\AccountReactivationResource;
use App\Models\AccountReactivationRequest;
use App\Models\AuditEvent;
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

class AccountReactivationController extends Controller
{
    public function store(StoreReactivationRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            [$reactivation, $created] = DB::transaction(function () use ($request, $data): array {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_if($user->must_change_password, 403, 'Replace your temporary password first.');
                abort_if($user->inactivity_suspended_at === null, 409, 'This account is not suspended for inactivity.');
                $existing = AccountReactivationRequest::whereKey($data['request_id'])->lockForUpdate()->first();
                if ($existing !== null) {
                    abort_unless($existing->user_id === $user->id && $existing->suspension_version === $user->inactivity_version
                        && $existing->request_reason === ($data['reason'] ?? null), 409, 'This request ID was already used.');

                    return [$existing, false];
                }
                abort_if(AccountReactivationRequest::where('active_user_id', $user->id)->exists(), 409, 'A reactivation request is already active.');
                $reactivation = new AccountReactivationRequest([
                    'user_id' => $user->id, 'active_user_id' => $user->id,
                    'suspension_version' => $user->inactivity_version, 'request_reason' => $data['reason'] ?? null,
                ]);
                $reactivation->id = $data['request_id'];
                $reactivation->save();
                $reactivation->refresh();
                $this->audit($user, $reactivation, 'account.reactivation.requested');

                return [$reactivation, true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'Another reactivation request was submitted. Refresh before retrying.');
        }

        return (new AccountReactivationResource($reactivation))->response()->setStatusCode($created ? 201 : 200);
    }

    public function ownIndex(Request $request): AnonymousResourceCollection
    {
        return $this->listing($request, AccountReactivationRequest::where('user_id', $request->user()->id));
    }

    public function staffIndex(Request $request): AnonymousResourceCollection
    {
        return $this->listing($request, AccountReactivationRequest::where('status', 'PENDING'));
    }

    public function show(Request $request, AccountReactivationRequest $reactivation): JsonResponse
    {
        abort_if($request->query() !== [], 422, 'Unsupported review filters.');
        $user = User::findOrFail($reactivation->user_id);
        $profile = CustomerProfile::where('user_id', $user->id)->first();

        return response()->json(['data' => [
            'request' => (new AccountReactivationResource($reactivation))->resolve($request),
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'lastLoginAt' => $user->last_login_at?->toISOString(),
                'suspendedAt' => $user->inactivity_suspended_at?->toISOString(), 'approval' => $profile?->approval],
            'request_reason' => $reactivation->request_reason, 'decision_reason' => $reactivation->decision_reason,
        ]]);
    }

    public function decide(DecideReactivationRequest $request, AccountReactivationRequest $reactivation): AccountReactivationResource
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($request, $reactivation, $data): AccountReactivationRequest {
            $user = User::whereKey($reactivation->user_id)->lockForUpdate()->firstOrFail();
            $current = AccountReactivationRequest::whereKey($reactivation->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('CUSTOMER_APPROVE');
            abort_if($user->id === $request->user()->id, 403, 'A different reviewer must decide your request.');
            abort_if($current->status !== 'PENDING' || $current->version !== (int) $data['version']
                || $user->inactivity_suspended_at === null || $user->inactivity_version !== $current->suspension_version,
                409, 'The request or inactivity cycle has changed. Review again.');
            $current->status = $data['decision'] === 'APPROUVE' ? 'APPROVED' : 'REFUSED';
            if ($current->status === 'REFUSED') {
                $current->active_user_id = null;
            }
            $current->version++;
            $current->reviewed_by = $request->user()->id;
            $current->reviewed_at = now('UTC');
            $current->decision_reason = $data['reason'];
            $current->save();
            $this->audit($request->user(), $current, 'account.reactivation.decided');

            return $current;
        }, 3);

        return new AccountReactivationResource($updated);
    }

    /** @param Builder<AccountReactivationRequest> $query */
    private function listing(Request $request, Builder $query): AnonymousResourceCollection
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page']) !== [], 422, 'Unsupported request filter.');

        return AccountReactivationResource::collection($query->orderBy('created_at')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 20))->withQueryString());
    }

    private function audit(User $actor, AccountReactivationRequest $reactivation, string $action): void
    {
        AuditEvent::create([
            'actor_id' => $actor->id, 'request_id' => (string) Str::uuid(), 'action' => $action,
            'object_type' => 'account_reactivation_request', 'object_id' => $reactivation->id,
            'result' => 'SUCCESS', 'reason' => null,
            'changes' => ['after' => ['status' => $reactivation->status, 'version' => $reactivation->version]],
            'occurred_at' => now('UTC'),
        ]);
    }
}
