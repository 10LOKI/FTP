<?php

namespace App\Services;

use App\Mail\CustomerApprovedMail;
use App\Models\ApprovalNotification;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use RuntimeException;
use Throwable;

class ApprovalNotificationSender
{
    public function send(string $notificationId): bool
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Approval notifications must be sent after the approval transaction commits.');
        }

        return DB::transaction(function () use ($notificationId): bool {
            $notification = ApprovalNotification::whereKey($notificationId)->readyForDelivery()->lockForUpdate()->first();

            if ($notification === null) {
                return false;
            }

            $before = $notification->status;
            $notification->attempts++;
            $notification->last_attempt_at = now('UTC');

            try {
                $sent = Mail::to($notification->recipient_email)
                    ->send(new CustomerApprovedMail($notification->customer_code, $notification->portal_url));

                if ($sent === null) {
                    throw new RuntimeException('The mail transport did not accept the message.');
                }

                $notification->status = 'SENT';
                $notification->sent_at = now('UTC');
                $notification->next_attempt_at = null;
                $notification->last_error_code = null;
            } catch (Throwable) {
                $notification->status = 'RETRY';
                $notification->next_attempt_at = now('UTC')->addMinutes(5);
                $notification->last_error_code = 'MAIL_TRANSPORT_FAILED';
            }

            $notification->save();
            $approvalEvent = AuditEvent::findOrFail($notification->approval_event_id);
            AuditEvent::create([
                'actor_id' => null,
                'request_id' => $approvalEvent->request_id,
                'action' => 'notification.delivery',
                'object_type' => 'approval_notification',
                'object_id' => $notification->id,
                'result' => $notification->status === 'SENT' ? 'SUCCESS' : 'FAILED',
                'reason' => $notification->status === 'SENT' ? 'mail_transport_accepted' : 'mail_transport_failed',
                'changes' => ['before' => ['status' => $before], 'after' => [
                    'status' => $notification->status,
                    'attempt' => $notification->attempts,
                    'approval_event_id' => $notification->approval_event_id,
                ]],
                'occurred_at' => now('UTC'),
            ]);

            return $notification->status === 'SENT';
        });
    }
}
