<?php

namespace App\Services;

use App\Mail\EmployeeTemporaryAccessMail;
use App\Models\AuditEvent;
use App\Models\EmployeeAccessDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class EmployeeAccessSender
{
    public function send(string $id): bool
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Send employee access only after provisioning commits.');
        }
        $userId = EmployeeAccessDelivery::findOrFail($id)->user_id;

        return DB::transaction(function () use ($id, $userId): bool {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $delivery = EmployeeAccessDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($delivery->status, ['PENDING', 'RETRY'], true)
                || $delivery->next_attempt_at?->isFuture()) {
                return false;
            }
            if (! $user->must_change_password || $delivery->expires_at->lessThanOrEqualTo(now('UTC'))
                || $user->temporary_password_expires_at === null || $user->temporary_password_expires_at->lessThanOrEqualTo(now('UTC'))) {
                $delivery->status = 'EXPIRED';
                $delivery->last_error_code = 'TEMPORARY_ACCESS_EXPIRED';
                $delivery->save();
                $this->audit($delivery, false);

                return false;
            }
            $delivery->attempts++;
            try {
                $driver = config('mail.mailers.'.config('mail.default').'.transport');
                $safe = in_array($driver, ['smtp', 'ses', 'postmark', 'resend'], true)
                    || ($driver === 'array' && app()->environment('testing'));
                if (! $safe) {
                    throw new RuntimeException('Unsafe temporary-secret mail transport.');
                }
                $url = (string) config('employee_access.portal_url');
                if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
                    throw new RuntimeException('Invalid employee portal configuration.');
                }
                $secret = bin2hex(random_bytes(24));
                $user->password = $secret;
                $user->access_version++;
                $user->save();
                $sent = Mail::to($user->email)->send(new EmployeeTemporaryAccessMail($secret, $url, $delivery->expires_at->toISOString()));
                if ($sent === null) {
                    throw new RuntimeException('Mail transport did not accept access.');
                }
                $delivery->status = 'SENT';
                $delivery->sent_at = now('UTC');
                $delivery->next_attempt_at = null;
                $delivery->last_error_code = null;
            } catch (Throwable) {
                $delivery->status = 'RETRY';
                $delivery->last_error_code = 'ACCESS_DELIVERY_FAILED';
                $delivery->next_attempt_at = now('UTC')->addMinutes(5);
            } finally {
                unset($secret);
            }
            $delivery->save();
            $this->audit($delivery, $delivery->status === 'SENT');

            return $delivery->status === 'SENT';
        });
    }

    private function audit(EmployeeAccessDelivery $delivery, bool $accepted): void
    {
        AuditEvent::create([
            'actor_id' => null, 'request_id' => (string) Str::uuid(),
            'action' => 'employee.access.delivery', 'object_type' => 'employee_access_delivery', 'object_id' => $delivery->id,
            'result' => $accepted ? 'SUCCESS' : 'FAILED', 'reason' => $accepted ? 'transport_accepted' : 'access_delivery_unavailable',
            'changes' => ['after' => ['status' => $delivery->status, 'attempt' => $delivery->attempts]], 'occurred_at' => now('UTC'),
        ]);
    }
}
