<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideCustomerApprovalRequest;
use App\Http\Resources\CustomerApprovalDecisionResource;
use App\Models\ApprovalNotification;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\IssuedCustomerCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerApprovalDecisionController extends Controller
{
    public function __invoke(DecideCustomerApprovalRequest $request, CustomerProfile $customerProfile): CustomerApprovalDecisionResource
    {
        $data = $request->validated();

        try {
            $profile = DB::transaction(function () use ($request, $customerProfile, $data): CustomerProfile {
                $profile = CustomerProfile::whereKey($customerProfile->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('CUSTOMER_APPROVE');
                abort_if($profile->approval !== 'EN_ATTENTE' || $profile->version !== (int) $data['version'],
                    409, 'This customer request has already changed. Refresh before deciding.');

                $before = ['approval' => $profile->approval, 'customer_code' => $profile->customer_code, 'version' => $profile->version];

                if ($data['decision'] === 'APPROUVE') {
                    $profile->customer_code = IssuedCustomerCode::issueFor($profile, $data['customer_code'] ?? null);
                }

                $profile->approval = $data['decision'];
                $profile->decided_by = $request->user()->getAuthIdentifier();
                $profile->decided_at = now('UTC');
                $profile->decision_reason = $data['reason'];
                $profile->version++;
                $profile->save();

                $approvalEvent = AuditEvent::create([
                    'actor_id' => $request->user()->getAuthIdentifier(),
                    'request_id' => $request->attributes->get('approval_request_id'),
                    'action' => 'customer.approval_decision',
                    'object_type' => 'customer_profile',
                    'object_id' => $profile->id,
                    'result' => 'SUCCESS',
                    'reason' => $data['decision'] === 'APPROUVE' ? 'approval_recorded' : 'refusal_recorded',
                    'changes' => ['before' => $before, 'after' => [
                        'approval' => $profile->approval, 'customer_code' => $profile->customer_code, 'version' => $profile->version,
                    ]],
                    'occurred_at' => now('UTC'),
                ]);

                if ($data['decision'] === 'APPROUVE') {
                    $portalUrl = (string) config('mail.customer_portal_url');
                    abort_unless(filter_var($portalUrl, FILTER_VALIDATE_URL)
                        && in_array(parse_url($portalUrl, PHP_URL_SCHEME), ['http', 'https'], true),
                        500, 'The customer portal URL must be configured before approval.');

                    $notification = ApprovalNotification::create([
                        'customer_profile_id' => $profile->id,
                        'approval_event_id' => $approvalEvent->id,
                        'recipient_email' => $profile->user()->value('email'),
                        'customer_code' => $profile->customer_code,
                        'portal_url' => $portalUrl,
                    ]);

                    AuditEvent::create([
                        'actor_id' => $request->user()->getAuthIdentifier(),
                        'request_id' => $request->attributes->get('approval_request_id'),
                        'action' => 'notification.requested',
                        'object_type' => 'approval_notification',
                        'object_id' => $notification->id,
                        'result' => 'SUCCESS',
                        'reason' => 'approval_email_pending',
                        'changes' => ['before' => null, 'after' => [
                            'status' => 'PENDING', 'approval_event_id' => $approvalEvent->id,
                        ]],
                        'occurred_at' => now('UTC'),
                    ]);
                }

                return $profile;
            });
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'The customer code was issued by another operation. Refresh before deciding.');
        }

        return new CustomerApprovalDecisionResource($profile);
    }
}
