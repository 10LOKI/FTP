<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideCustomerIdentityChangeRequest;
use App\Http\Requests\StoreCustomerIdentityChangeRequest;
use App\Http\Resources\CustomerIdentityChangeResource;
use App\Models\AuditEvent;
use App\Models\CustomerIdentityChange;
use App\Models\CustomerProfile;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CustomerIdentityChangeController extends Controller
{
    public function store(StoreCustomerIdentityChangeRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            [$change, $created] = DB::transaction(function () use ($request, $data): array {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_if($user->must_change_password, 403, 'Change your temporary password first.');
                $profile = CustomerProfile::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                abort_unless($profile->approval === 'APPROUVE', 403, 'Customer approval is required.');
                $existing = CustomerIdentityChange::whereKey($data['request_id'])->lockForUpdate()->first();
                if ($existing !== null) {
                    abort_unless($existing->customer_profile_id === $profile->id
                        && $existing->profile_version === (int) $data['version']
                        && $existing->name === $data['name'] && $existing->email === $data['email']
                        && $existing->type === $data['type']
                        && $existing->company_name === ($data['company_name'] ?? null)
                        && $existing->responsible_name === ($data['responsible_name'] ?? null), 409, 'This request ID was already used.');

                    return [$existing, false];
                }
                abort_if($profile->version !== (int) $data['version'], 409, 'Your profile has changed. Refresh before submitting.');
                abort_if(CustomerIdentityChange::where('active_customer_profile_id', $profile->id)->exists(),
                    409, 'An identity change is already awaiting review.');
                $change = new CustomerIdentityChange([
                    'customer_profile_id' => $profile->id, 'active_customer_profile_id' => $profile->id,
                    'profile_version' => $profile->version,
                    'name' => $data['name'], 'email' => $data['email'], 'type' => $data['type'],
                    'company_name' => $data['company_name'] ?? null, 'responsible_name' => $data['responsible_name'] ?? null,
                ]);
                $change->id = $data['request_id'];
                if (strcasecmp($user->email, $change->email) === 0) {
                    $change->email_verified_at = $user->email_verified_at;
                } else {
                    $change->verification_expires_at = now('UTC')->addDay();
                }
                $change->save();
                $change->refresh();
                $event = $this->audit($user, $change, 'customer.identity_change.requested', null);
                if ($change->verification_expires_at !== null) {
                    $this->queueVerification($user, $change, $event);
                }

                return [$change, true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'Another identity request was submitted. Refresh before retrying.');
        }

        return (new CustomerIdentityChangeResource($change))->response()->setStatusCode($created ? 201 : 200);
    }

    public function ownIndex(Request $request): AnonymousResourceCollection
    {
        $profile = CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();

        return $this->listing($request, CustomerIdentityChange::where('customer_profile_id', $profile->id));
    }

    public function staffIndex(Request $request): AnonymousResourceCollection
    {
        return $this->listing($request, CustomerIdentityChange::where('status', 'PENDING'));
    }

    public function show(Request $request, CustomerIdentityChange $change): JsonResponse
    {
        abort_if($request->query() !== [], 422, 'Identity review does not accept filters.');
        $profile = CustomerProfile::with('user:id,name,email')->findOrFail($change->customer_profile_id);

        return response()->json(['data' => [
            'change' => (new CustomerIdentityChangeResource($change))->resolve($request),
            'customerId' => $profile->id,
            'current_profile' => [...$profile->only(['version', 'type', 'company_name', 'responsible_name']), 'name' => $profile->user->name, 'email' => $profile->user->email],
            'decision_reason' => $change->decision_reason,
        ]]);
    }

    public function decide(DecideCustomerIdentityChangeRequest $request, CustomerIdentityChange $change): CustomerIdentityChangeResource
    {
        $data = $request->validated();
        $ownerId = CustomerProfile::findOrFail($change->customer_profile_id)->user_id;
        try {
            $updated = DB::transaction(function () use ($request, $change, $data, $ownerId): CustomerIdentityChange {
                $owner = User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
                $profile = CustomerProfile::whereKey($change->customer_profile_id)->lockForUpdate()->firstOrFail();
                $current = CustomerIdentityChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('CUSTOMER_APPROVE');
                abort_if($profile->user_id === $request->user()->id, 403, 'A different reviewer must decide your identity change.');
                abort_if($current->status !== 'PENDING' || $current->version !== (int) $data['version']
                    || $profile->version !== (int) $data['profile_version'],
                    409, 'The request or profile has changed. Review the latest details.');
                if ($data['decision'] === 'APPROUVE') {
                    abort_unless($profile->approval === 'APPROUVE', 409, 'The customer is no longer approved.');
                    $emailChanged = strcasecmp($owner->email, $current->email) !== 0;
                    abort_if($emailChanged && $current->email_verified_at === null, 409, 'Verify the proposed email before approval.');
                    abort_if(User::whereRaw('LOWER(email) = ?', [strtolower($current->email)])->whereKeyNot($owner->id)->exists(), 409, 'This email is now used by another account.');
                    $owner->name = $current->name;
                    $owner->email = $current->email;
                    if ($emailChanged) {
                        $owner->email_verified_at = $current->email_verified_at;
                    }
                    $owner->save();
                    $profile->fill($current->only(['type', 'company_name', 'responsible_name']));
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
                $this->audit($request->user(), $current, 'customer.identity_change.decided', $before);

                return $current;
            }, 3);

        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'This email was taken by another operation. Review the latest details.');
        }

        return new CustomerIdentityChangeResource($updated);
    }

    public function verifyEmail(Request $request, CustomerIdentityChange $change): JsonResponse
    {
        DB::transaction(function () use ($request, $change): void {
            $current = CustomerIdentityChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            abort_if($current->status !== 'PENDING'
                || (int) $request->query('revision') !== $current->verification_revision
                || $current->verification_expires_at === null || $current->verification_expires_at->isPast(),
                409, 'This verification is no longer current. Request a new link.');
            if ($current->email_verified_at === null) {
                $current->email_verified_at = now('UTC');
                $current->version++;
                $current->save();
                $owner = User::findOrFail(CustomerProfile::findOrFail($current->customer_profile_id)->user_id);
                $this->audit($owner, $current, 'customer.identity_change.email_verified', 'PENDING');
            }
        });

        return response()->json(['message' => 'Email verified. Staff review is still required.']);
    }

    public function resend(Request $request, CustomerIdentityChange $change): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        abort_if(array_diff(array_keys($request->all()), ['version', '_token']) !== [], 422, 'Unsupported verification fields.');
        DB::transaction(function () use ($request, $change, $data): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $profile = CustomerProfile::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $current = CustomerIdentityChange::whereKey($change->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->customer_profile_id === $profile->id, 404);
            abort_if($user->must_change_password || $profile->approval !== 'APPROUVE', 403);
            abort_if($current->status !== 'PENDING' || $current->verification_expires_at === null || $current->email_verified_at !== null
                || $current->version !== (int) $data['version'], 409, 'Refresh the request before requesting verification.');
            $current->verification_expires_at = now('UTC')->addDay();
            $current->verification_revision++;
            $current->version++;
            $current->save();
            $event = $this->audit($user, $current, 'customer.identity_change.verification_requested', 'PENDING');
            $this->queueVerification($user, $current, $event);
        });

        return response()->json(['message' => 'Verification delivery queued.'], 202);
    }

    private function queueVerification(User $owner, CustomerIdentityChange $change, AuditEvent $event): void
    {
        $url = URL::temporarySignedRoute('customer.identity.email.verify', $change->verification_expires_at, [
            'change' => $change->id, 'revision' => $change->verification_revision,
        ]);
        $delivery = NotificationDelivery::create([
            'trigger_event_id' => $event->id, 'recipient_id' => $owner->id,
            'type' => 'customer.identity.email_verification', 'channel' => 'email',
            'payload' => ['change_id' => $change->id, 'revision' => $change->verification_revision,
                'email' => $change->email, 'verification_url' => $url],
        ]);
        AuditEvent::create([
            'actor_id' => $owner->id, 'request_id' => $event->request_id,
            'action' => 'notification.requested', 'object_type' => 'notification_delivery', 'object_id' => $delivery->id,
            'result' => 'SUCCESS', 'changes' => ['after' => ['status' => 'PENDING', 'trigger_event_id' => $event->id]],
            'occurred_at' => now('UTC'),
        ]);
    }

    /** @param Builder<CustomerIdentityChange> $query */
    private function listing(Request $request, Builder $query): AnonymousResourceCollection
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        abort_if(array_diff(array_keys($request->query()), ['page', 'per_page']) !== [], 422, 'Unsupported identity-request filter.');

        return CustomerIdentityChangeResource::collection($query->orderBy('created_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20))->withQueryString());
    }

    private function audit(User $actor, CustomerIdentityChange $change, string $action, ?string $before): AuditEvent
    {
        return AuditEvent::create([
            'actor_id' => $actor->id, 'request_id' => (string) Str::uuid(),
            'action' => $action, 'object_type' => 'customer_identity_change', 'object_id' => $change->id,
            'result' => 'SUCCESS', 'reason' => null,
            'changes' => ['before' => $before === null ? null : ['status' => $before],
                'after' => ['status' => $change->status, 'version' => $change->version, 'customer_profile_id' => $change->customer_profile_id]],
            'occurred_at' => now('UTC'),
        ]);
    }
}
