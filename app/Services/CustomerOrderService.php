<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class CustomerOrderService
{
    public function __construct(private readonly CartService $carts) {}

    /** @param array<string, mixed> $input
     * @return array{order: stdClass, replayed: bool}
     */
    public function submit(int $userId, array $input, string $requestId): array
    {
        $input['idempotency_key'] = Str::lower($input['idempotency_key']);

        return DB::transaction(function () use ($userId, $input, $requestId): array {
            $profile = $this->customer($userId);
            $canonical = [
                'expected_version' => (int) $input['expected_version'], 'pricing_token' => $input['pricing_token'],
                'contact' => $this->fields($input['contact'], ['name', 'email', 'phone']),
                'delivery_address' => $this->fields($input['delivery_address'], ['address', 'city', 'postal_code', 'country']),
                'billing_address' => $this->fields($input['billing_address'], ['address', 'city', 'postal_code', 'country']),
                'customer_note' => $input['customer_note'] ?? null,
            ];
            $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
            $existing = DB::table('customer_orders')->where('user_id', $userId)
                ->where('idempotency_key', $input['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Cette clé a déjà servi pour une autre demande.');
                $this->audit($userId, $requestId, $existing->id, 'order.replayed');

                return ['order' => $existing, 'replayed' => true];
            }

            $cart = $this->carts->snapshot($userId);
            abort_unless($cart['version'] === $canonical['expected_version'], 409, 'Le panier a changé. Vérifiez-le à nouveau.');
            abort_unless($cart['lines'] !== [] && $cart['pricing_status'] === 'customer_estimate'
                && collect($cart['lines'])->every(fn (array $line): bool => $line['orderable']), 422, 'Le panier contient un article indisponible ou sans prix.');
            abort_unless(hash_equals(CartService::pricingToken($cart), $canonical['pricing_token']), 409, 'Les prix ont changé. Vérifiez le nouveau panier avant de soumettre.');
            foreach ($cart['lines'] as $line) {
                abort_unless($line['units_per_package'] === 1 || DB::table('product_barcodes')
                    ->where('product_id', $line['product_id'])->where('units_per_package', $line['units_per_package'])->exists(),
                    422, 'Un conditionnement a changé. Vérifiez votre panier.');
            }

            $now = now();
            $lines = array_map(function (array $line): array {
                $line['id'] = (string) Str::uuid();

                return $line;
            }, $cart['lines']);
            abort_if(bccomp($cart['totals']['catalogue_subtotal'], '9999999999999999999999.99', 2) > 0, 422, 'Le montant de cette demande nécessite un traitement commercial.');
            $id = DB::table('customer_orders')->insertGetId([
                'user_id' => $userId, 'number' => 'CMD-'.Str::ulid(), 'status' => 'SOUMISE', 'version' => 1,
                'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash,
                'customer_snapshot' => json_encode(['id' => $profile->id, 'customer_code' => $profile->customer_code,
                    'type' => $profile->type, 'company_name' => $profile->company_name], JSON_THROW_ON_ERROR),
                'commercial_snapshot' => json_encode(['fees' => null,
                    'currency' => $cart['lines'][0]['pricing']['currency'],
                    'tax_basis' => $cart['lines'][0]['pricing']['tax_basis'],
                    'discounts' => array_map(fn (array $line): array => ['line_id' => $line['id'], 'pricing' => $line['pricing']], $lines),
                    'status' => 'pending_human_review'], JSON_THROW_ON_ERROR),
                'contact' => json_encode($canonical['contact'], JSON_THROW_ON_ERROR),
                'delivery_address' => json_encode($canonical['delivery_address'], JSON_THROW_ON_ERROR),
                'billing_address' => json_encode($canonical['billing_address'], JSON_THROW_ON_ERROR),
                'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
                'catalogue_subtotal' => $cart['totals']['catalogue_subtotal'], 'pricing_status' => 'customer_estimate',
                'customer_note' => $canonical['customer_note'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('cart_lines')->where('cart_id', $cart['id'])->delete();
            DB::table('carts')->where('id', $cart['id'])->increment('version', 1, ['updated_at' => $now]);
            $event = $this->audit($userId, $requestId, $id, 'order.submitted');
            app(DurableNotifications::class)->orderReceived(DB::table('customer_orders')->where('id', $id)->first(), $event);

            return ['order' => DB::table('customer_orders')->where('id', $id)->first(), 'replayed' => false];
        }, 3);
    }

    public function history(int $userId): LengthAwarePaginator
    {
        $this->customer($userId);

        return DB::table('customer_orders')->where('user_id', $userId)->orderByDesc('id')->paginate(20);
    }

    public function owned(int $userId, int $orderId): stdClass
    {
        $this->customer($userId);
        $order = DB::table('customer_orders')->where('user_id', $userId)->where('id', $orderId)->first();
        abort_unless($order !== null, 404);

        return $order;
    }

    private function customer(int $userId): CustomerProfile
    {
        $profile = CustomerProfile::query()->where('user_id', $userId)->lockForUpdate()->first();
        abort_unless($profile !== null && $profile->approval === 'APPROUVE', 403);

        return $profile;
    }

    /** @param array<string, mixed> $values
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function fields(array $values, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $values[$key] ?? null;
        }

        return $result;
    }

    private function audit(int $userId, string $requestId, int $orderId, string $action): AuditEvent
    {
        return AuditEvent::create(['actor_id' => $userId, 'request_id' => $requestId, 'action' => $action,
            'object_type' => 'customer_order', 'object_id' => (string) $orderId, 'result' => 'SUCCESS',
            'changes' => null, 'occurred_at' => now('UTC')]);
    }
}
