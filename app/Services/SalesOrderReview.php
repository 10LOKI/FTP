<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SalesOrderReview
{
    public function __construct(private readonly SalesLineProposal $lineProposals) {}

    /** @param list<array{product_id: int|string, quantity: int|string, units_per_package: int|string}> $lines
     * @return array<string, mixed>
     */
    public function previewLines(User $actor, int $orderId, array $lines, int $expectedVersion): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $orderId, $lines, $expectedVersion): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order !== null, 404);
            $review = DB::table('sales_order_reviews')->where('order_id', $orderId)->first();
            abort_unless($review !== null && (int) $review->claimed_by === $actor->id, 403);
            abort_unless(in_array($order->status, ['EN_VERIFICATION', 'ATTENTE_ACCORD'], true) && (int) $order->version === $expectedVersion, 409);

            return $this->lineProposals->calculate((int) $order->user_id, $lines) + ['version' => (int) $order->version];
        }, 3);
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('ORDER_REVIEW_MANAGE');
        abort_if(CustomerProfile::query()->where('user_id', $actor->id)->exists(), 403);
    }

    /** @return array<string, mixed> */
    public function claim(User $actor, int $orderId, int $expectedVersion): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $orderId, $expectedVersion): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order !== null, 404);
            $review = DB::table('sales_order_reviews')->where('order_id', $orderId)->first();
            if ($review !== null) {
                abort_unless((int) $review->claimed_by === $actor->id, 409, 'Cette demande est déjà prise en charge.');

                return ['order_id' => (string) $orderId, 'version' => (int) $order->version, 'status' => $order->status];
            }
            abort_unless($order->status === 'SOUMISE' && (int) $order->version === $expectedVersion, 409, 'État ou version périmés.');
            DB::table('sales_order_reviews')->insert(['order_id' => $orderId, 'claimed_by' => $actor->id, 'claimed_at' => now('UTC')]);
            DB::table('customer_orders')->where('id', $orderId)->update(['status' => 'EN_VERIFICATION', 'version' => $expectedVersion + 1, 'updated_at' => now()]);
            $this->audit($actor, $orderId, 'sales.order_claimed', $expectedVersion + 1);

            return ['order_id' => (string) $orderId, 'version' => $expectedVersion + 1, 'status' => 'EN_VERIFICATION'];
        }, 3);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function recordCall(User $actor, int $orderId, array $values): array
    {
        $this->authorize($actor);
        $key = Str::lower($values['request_key']);
        $payload = ['expected_version' => (int) $values['expected_version'], 'outcome' => $values['outcome'],
            'happened_at' => $values['happened_at'], 'note' => $values['note'] ?? null];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $orderId, $payload, $key, $hash): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order !== null, 404);
            $review = DB::table('sales_order_reviews')->where('order_id', $orderId)->first();
            abort_unless($review !== null && (int) $review->claimed_by === $actor->id, 403);
            $existing = DB::table('sales_order_calls')->where('order_id', $orderId)->where('request_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Clé déjà utilisée pour un autre appel.');

                return ['call_id' => $existing->id, 'version' => (int) $existing->order_version, 'replayed' => true];
            }
            abort_unless(in_array($order->status, ['EN_VERIFICATION', 'ATTENTE_ACCORD'], true) && (int) $order->version === $payload['expected_version'], 409, 'État ou version périmés.');
            $id = (string) Str::uuid();
            $version = (int) $order->version + 1;
            DB::table('sales_order_calls')->insert(['id' => $id, 'order_id' => $orderId, 'actor_id' => $actor->id,
                'request_key' => $key, 'request_hash' => $hash, 'outcome' => $payload['outcome'],
                'happened_at' => $payload['happened_at'], 'note' => $payload['note'], 'order_version' => $version, 'created_at' => now('UTC')]);
            DB::table('customer_orders')->where('id', $orderId)->update(['version' => $version, 'updated_at' => now()]);
            $this->audit($actor, $orderId, 'sales.call_recorded', $version);

            return ['call_id' => $id, 'version' => $version, 'replayed' => false];
        }, 3);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function proposeRevision(User $actor, int $orderId, array $values): array
    {
        $this->authorize($actor);
        $key = Str::lower($values['request_key']);
        $changes = $values['changes'];
        ksort($changes);
        $identity = [(int) $values['expected_version'], $values['kind'], $changes, $values['reason']];
        if ($values['kind'] === 'lines') {
            $identity[] = $values['pricing_token'];
        }
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $orderId, $values, $key, $changes, $hash): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order !== null, 404);
            $review = DB::table('sales_order_reviews')->where('order_id', $orderId)->first();
            abort_unless($review !== null && (int) $review->claimed_by === $actor->id, 403);
            $existing = DB::table('sales_order_revisions')->where('order_id', $orderId)->where('request_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Clé déjà utilisée pour une autre révision.');

                return ['revision_id' => $existing->id, 'version' => (int) $existing->order_version, 'requires_agreement' => true, 'replayed' => true];
            }
            abort_unless(in_array($order->status, ['EN_VERIFICATION', 'ATTENTE_ACCORD'], true) && (int) $order->version === (int) $values['expected_version'], 409, 'État ou version périmés.');
            $previous = DB::table('sales_order_revisions')->where('order_id', $orderId)->where('kind', $values['kind'])->orderByDesc('order_version')->first();
            if ($values['kind'] === 'lines') {
                $before = $previous === null ? ['lines' => json_decode($order->lines, true, 512, JSON_THROW_ON_ERROR)]
                    : json_decode($previous->after, true, 512, JSON_THROW_ON_ERROR);
                $proposal = $this->lineProposals->calculate((int) $order->user_id, $changes['lines']);
                abort_unless(hash_equals($proposal['pricing_token'], $values['pricing_token']), 409, 'Le tarif a changé. Prévisualisez à nouveau.');
                $proposal['lines'] = array_map(function (array $line) use ($before): array {
                    $old = collect($before['lines'])->first(fn (array $original): bool => (string) $original['product_id'] === $line['product_id']
                        && (int) $original['units_per_package'] === $line['units_per_package']);

                    return ['id' => $old['id'] ?? (string) Str::uuid()] + $line;
                }, $proposal['lines']);
                $changes = $proposal;
            } else {
                $fields = $values['kind'] === 'contact' ? ['name', 'email', 'phone'] : ['address', 'city', 'postal_code', 'country'];
                $before = Arr::only(json_decode($previous?->after ?? $order->{$values['kind']}, true, 512, JSON_THROW_ON_ERROR), $fields);
            }
            $id = (string) Str::uuid();
            $version = (int) $order->version + 1;
            DB::table('sales_order_revisions')->insert(['id' => $id, 'order_id' => $orderId, 'actor_id' => $actor->id,
                'request_key' => $key, 'request_hash' => $hash, 'kind' => $values['kind'],
                'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($changes, JSON_THROW_ON_ERROR),
                'reason' => $values['reason'], 'order_version' => $version, 'created_at' => now('UTC')]);
            DB::table('customer_orders')->where('id', $orderId)->update(['version' => $version, 'updated_at' => now()]);
            $this->audit($actor, $orderId, 'sales.revision_proposed', $version);

            return ['revision_id' => $id, 'version' => $version, 'requires_agreement' => true, 'replayed' => false];
        }, 3);
    }

    private function audit(User $actor, int $orderId, string $action, int $version): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'request_id' => (string) Str::uuid(), 'action' => $action,
            'object_type' => 'customer_order', 'object_id' => (string) $orderId, 'result' => 'SUCCESS',
            'changes' => ['after' => ['version' => $version]], 'occurred_at' => now('UTC')]);
    }
}
