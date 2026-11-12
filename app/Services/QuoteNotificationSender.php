<?php

namespace App\Services;

use App\Mail\QuoteAvailableMail;
use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class QuoteNotificationSender
{
    public function enqueue(object $order, string $quoteId, AuditEvent $event): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Quote notification intent must share the issuance transaction.');
        }
        DB::table('sales_quote_notifications')->insert(['id' => (string) Str::uuid(), 'quote_id' => $quoteId,
            'trigger_event_id' => $event->id, 'recipient_id' => $order->user_id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    public function send(string $id): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Deliver only committed quote notification intents.');
        }
        if (config('quotes.notifications_enabled') !== true) {
            return false;
        }
        $intent = DB::table('sales_quote_notifications')->where('id', $id)->firstOrFail();
        $quote = DB::table('sales_quotes')->where('id', $intent->quote_id)->firstOrFail();

        return DB::transaction(function () use ($id, $quote): bool {
            $order = DB::table('customer_orders')->where('id', $quote->order_id)->lockForUpdate()->firstOrFail();
            $intent = DB::table('sales_quote_notifications')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! in_array($intent->status, ['PENDING', 'RETRY'], true)
                || ($intent->next_attempt_at !== null && CarbonImmutable::parse($intent->next_attempt_at, 'UTC')->isFuture())) {
                return false;
            }
            $current = $quote->valid_until >= now('UTC')->toDateString() && $order->status === 'ATTENTE_ACCORD'
                && (int) $order->version === (int) $quote->order_version
                && ! DB::table('sales_quote_decisions')->where('quote_id', $quote->id)->exists();
            if (! $current) {
                $this->record($intent, ['status' => 'EXPIRED', 'next_attempt_at' => null, 'last_error_code' => 'OFFER_NOT_CURRENT'], 'REFUSED');

                return false;
            }
            $profile = CustomerProfile::where('user_id', $order->user_id)->lockForUpdate()->first();
            $user = User::whereKey($order->user_id)->lockForUpdate()->first();
            $attempt = ['attempts' => (int) $intent->attempts + 1, 'last_attempt_at' => now('UTC')];
            if ((int) $intent->recipient_id !== (int) $order->user_id || $profile?->approval !== 'APPROUVE'
                || $user === null || $user->inactivity_suspended_at !== null || $user->must_change_password) {
                $this->record($intent, $attempt + ['status' => 'RETRY', 'next_attempt_at' => $this->retryAt(), 'last_error_code' => 'ACCOUNT_UNAVAILABLE'], 'REFUSED');

                return false;
            }
            try {
                $transport = config('mail.mailers.'.config('mail.default').'.transport');
                if (! in_array($transport, ['smtp', 'ses', 'postmark', 'resend'], true)
                    && ! ($transport === 'array' && app()->environment('testing'))) {
                    throw new RuntimeException('A non-logging delivery transport is required.');
                }
                $sent = Mail::to($user->email)->send(new QuoteAvailableMail($quote->number, route('customer.orders.page'), $intent->id));
                if ($sent === null) {
                    throw new RuntimeException('Transport did not accept the notification.');
                }
                $state = ['status' => 'SENT', 'sent_at' => now('UTC'), 'next_attempt_at' => null, 'last_error_code' => null];
            } catch (Throwable) {
                $state = ['status' => 'RETRY', 'next_attempt_at' => $this->retryAt(), 'last_error_code' => 'DELIVERY_FAILED'];
            }
            $this->record($intent, $attempt + $state, $state['status'] === 'SENT' ? 'SUCCESS' : 'FAILED');

            return $state['status'] === 'SENT';
        }, 1);
    }

    private function retryAt(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addMinutes(max(1, (int) config('notifications.retry_minutes', 5)));
    }

    /** @param array<string, mixed> $values */
    private function record(object $intent, array $values, string $result): void
    {
        DB::table('sales_quote_notifications')->where('id', $intent->id)->update($values + ['updated_at' => now('UTC')]);
        AuditEvent::create(['actor_id' => null, 'request_id' => (string) Str::uuid(), 'action' => 'sales.quote_notification',
            'object_type' => 'sales_quote_notification', 'object_id' => $intent->id, 'result' => $result,
            'reason' => $values['last_error_code'] ?? 'transport_accepted',
            'changes' => ['after' => ['status' => $values['status'], 'attempts' => $values['attempts'] ?? (int) $intent->attempts,
                'quote_id' => $intent->quote_id, 'trigger_event_id' => $intent->trigger_event_id]], 'occurred_at' => now('UTC')]);
    }
}
