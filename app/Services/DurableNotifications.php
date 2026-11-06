<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

class DurableNotifications
{
    public function orderReceived(stdClass $order, AuditEvent $event): ?NotificationDelivery
    {
        if (! config('notifications.order_received_enabled')) {
            return null;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Notification intent must share the business transaction.');
        }
        $delivery = NotificationDelivery::firstOrCreate([
            'trigger_event_id' => $event->id, 'recipient_id' => $order->user_id,
            'type' => 'order.received', 'channel' => 'email',
        ], ['payload' => ['order_id' => (string) $order->id, 'order_number' => $order->number]]);
        if ($delivery->wasRecentlyCreated) {
            AuditEvent::create([
                'actor_id' => $event->actor_id, 'request_id' => $event->request_id,
                'action' => 'notification.requested', 'object_type' => 'notification_delivery', 'object_id' => $delivery->id,
                'result' => 'SUCCESS', 'changes' => ['after' => ['status' => 'PENDING', 'trigger_event_id' => $event->id]],
                'occurred_at' => now('UTC'),
            ]);
        }

        return $delivery;
    }
}
