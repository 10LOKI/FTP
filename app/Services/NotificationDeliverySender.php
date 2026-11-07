<?php

namespace App\Services;

use App\Mail\CustomerIdentityVerificationMail;
use App\Mail\OrderReceivedMail;
use App\Models\AuditEvent;
use App\Models\CustomerIdentityChange;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use RuntimeException;
use Throwable;

class NotificationDeliverySender
{
    public function send(string $id): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Deliver only committed notification intents.');
        }

        return DB::transaction(function () use ($id): bool {
            $delivery = NotificationDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($delivery->status, ['PENDING', 'RETRY'], true) || $delivery->next_attempt_at?->isFuture()) {
                return false;
            }
            if ($delivery->type === 'order.received' && ! config('notifications.order_received_enabled')) {
                return false;
            }
            $event = AuditEvent::findOrFail($delivery->trigger_event_id);
            $delivery->attempts++;
            $delivery->last_attempt_at = now('UTC');
            try {
                if ($delivery->channel !== 'email' || ! in_array($delivery->type, ['order.received', 'customer.identity.email_verification'], true)) {
                    throw new RuntimeException('Notification type is not enabled.');
                }
                $transport = config('mail.mailers.'.config('mail.default').'.transport');
                if (! in_array($transport, ['smtp', 'ses', 'postmark', 'resend'], true)
                    && ! ($transport === 'array' && app()->environment('testing'))) {
                    throw new RuntimeException('Use a delivery transport without message-body logging.');
                }
                $user = User::findOrFail($delivery->recipient_id);
                if ($delivery->type === 'customer.identity.email_verification') {
                    $change = CustomerIdentityChange::findOrFail($delivery->payload['change_id']);
                    if ($change->status !== 'PENDING' || $change->email_verified_at !== null
                        || $change->verification_revision !== (int) $delivery->payload['revision']
                        || $change->verification_expires_at === null || $change->verification_expires_at->isPast()) {
                        $delivery->status = 'EXPIRED';
                        $delivery->next_attempt_at = null;
                        $delivery->last_error_code = 'VERIFICATION_EXPIRED';
                        $delivery->save();
                        AuditEvent::create([
                            'actor_id' => null, 'request_id' => $event->request_id,
                            'action' => 'notification.delivery', 'object_type' => 'notification_delivery', 'object_id' => $delivery->id,
                            'result' => 'REFUSED', 'reason' => 'verification_expired',
                            'changes' => ['after' => ['status' => 'EXPIRED', 'attempt' => $delivery->attempts]],
                            'occurred_at' => now('UTC'),
                        ]);

                        return false;
                    }
                    $sent = Mail::to($delivery->payload['email'])->send(
                        new CustomerIdentityVerificationMail($delivery->payload['verification_url'], $delivery->id),
                    );
                } else {
                    $url = route('customer.orders.page');
                    $sent = Mail::to($user->email)->send(new OrderReceivedMail($delivery->payload['order_number'], $url, $delivery->id));
                }
                if ($sent === null) {
                    throw new RuntimeException('Transport did not accept the notification.');
                }
                $delivery->status = 'SENT';
                $delivery->sent_at = now('UTC');
                $delivery->next_attempt_at = null;
                $delivery->last_error_code = null;
            } catch (Throwable) {
                $delivery->status = 'RETRY';
                $delivery->next_attempt_at = now('UTC')->addMinutes((int) config('notifications.retry_minutes'));
                $delivery->last_error_code = 'DELIVERY_FAILED';
            }
            $delivery->save();
            AuditEvent::create([
                'actor_id' => null, 'request_id' => $event->request_id, 'action' => 'notification.delivery',
                'object_type' => 'notification_delivery', 'object_id' => $delivery->id,
                'result' => $delivery->status === 'SENT' ? 'SUCCESS' : 'FAILED',
                'reason' => $delivery->status === 'SENT' ? 'transport_accepted' : 'delivery_failed',
                'changes' => ['after' => ['status' => $delivery->status, 'attempt' => $delivery->attempts, 'trigger_event_id' => $event->id]],
                'occurred_at' => now('UTC'),
            ]);

            return $delivery->status === 'SENT';
        });
    }
}
