<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationDeliveryController extends Controller
{
    /** @var array<string, string> */
    private const SOURCES = ['approval' => 'approval_notifications', 'employee' => 'employee_access_deliveries', 'order' => 'notification_deliveries'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('AUDIT_READ');
        $values = $request->validate([
            'kind' => ['sometimes', Rule::in(array_keys(self::SOURCES))],
            'status' => ['sometimes', Rule::in(['PENDING', 'RETRY', 'SENT', 'EXPIRED'])],
            'page' => 'sometimes|integer|min:1|max:2147483647', 'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $union = null;
        foreach (self::SOURCES as $kind => $table) {
            $query = DB::table($table)->select(['id', 'status', 'attempts', 'next_attempt_at', 'sent_at', 'last_error_code', 'created_at'])
                ->selectRaw('? as kind', [$kind]);
            $union = $union === null ? $query : $union->unionAll($query);
        }
        $page = DB::query()->fromSub($union, 'deliveries')
            ->when(isset($values['kind']), fn (Builder $query): Builder => $query->where('kind', $values['kind']))
            ->when(isset($values['status']), fn (Builder $query): Builder => $query->where('status', $values['status']))
            ->orderByDesc('created_at')->orderBy('kind')->orderBy('id')->paginate($values['per_page'] ?? 20);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $kind, string $delivery): JsonResponse
    {
        Gate::authorize('NOTIFICATION_RETRY');
        abort_unless(isset(self::SOURCES[$kind]), 404);

        if ($kind === 'employee') {
            Gate::authorize('USER_RESET_ACCESS');
        }
        $values = $request->validate(['expected_attempts' => 'required|integer|min:0|max:4294967294']);
        abort_if(array_diff(array_keys($request->all()), ['expected_attempts', '_token']) !== [], 422, 'Champs de reprise non autorisés.');
        DB::transaction(function () use ($request, $kind, $delivery, $values): void {
            $row = DB::table(self::SOURCES[$kind])->where('id', $delivery)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            abort_if($kind === 'order' && $row->type === 'order.received' && ! config('notifications.order_received_enabled'), 409, 'Les notifications de commande ne sont pas encore activées.');
            abort_if($kind === 'employee' && CarbonImmutable::parse($row->expires_at, 'UTC')->lessThanOrEqualTo(now('UTC')), 409, 'L’accès temporaire a expiré. Utilisez la procédure de réémission autorisée.');
            abort_unless($row->status === 'RETRY' && (int) $row->attempts === (int) $values['expected_attempts'], 409,
                'Cet envoi a changé ou ne nécessite pas de reprise. Actualisez la liste.');
            DB::table(self::SOURCES[$kind])->where('id', $delivery)->update(['next_attempt_at' => now('UTC'), 'updated_at' => now('UTC')]);
            AuditEvent::create([
                'actor_id' => $request->user()->id, 'request_id' => (string) Str::uuid(),
                'action' => 'notification.retry_requested', 'object_type' => match ($kind) {
                    'approval' => 'approval_notification', 'employee' => 'employee_access_delivery', default => 'notification_delivery',
                }, 'object_id' => $delivery,
                'result' => 'SUCCESS', 'reason' => 'retry_scheduled',
                'changes' => ['after' => ['status' => 'RETRY', 'attempt' => (int) $row->attempts]], 'occurred_at' => now('UTC'),
            ]);
        });

        return response()->json(['data' => ['kind' => $kind, 'id' => $delivery, 'status' => 'RETRY'],
            'message' => 'Reprise programmée. Le worker conserve cette demande et son historique.'], 202)->header('Cache-Control', 'private, no-store');
    }
}
