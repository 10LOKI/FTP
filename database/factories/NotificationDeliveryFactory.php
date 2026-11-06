<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class NotificationDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'trigger_event_id' => fn (): string => AuditEvent::create([
                'actor_id' => null, 'request_id' => (string) Str::uuid(), 'action' => 'order.submitted',
                'object_type' => 'customer_order', 'object_id' => '1', 'result' => 'SUCCESS', 'occurred_at' => now('UTC'),
            ])->id,
            'recipient_id' => User::factory(), 'type' => 'order.received', 'channel' => 'email',
            'payload' => ['order_id' => '1', 'order_number' => 'CMD-FIXTURE'],
        ];
    }
}
