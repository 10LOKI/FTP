<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;

class WarehouseStock
{
    public const PERMISSIONS = [
        'warehouse' => 'STOCK_STRUCTURE_MANAGE', 'location' => 'STOCK_STRUCTURE_MANAGE',
        'configure' => 'STOCK_STRUCTURE_MANAGE', 'receive' => 'STOCK_RECEIVE',
        'adjust' => 'STOCK_ADJUST', 'inspect' => 'STOCK_ADJUST', 'transfer' => 'STOCK_TRANSFER',
    ];

    public function authorize(User $actor, string $permission): void
    {
        abort_if(CustomerProfile::where('user_id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize($permission);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function execute(User $actor, string $action, array $values, string $requestKey): array
    {
        abort_unless(isset(self::PERMISSIONS[$action]), 404);
        $this->authorize($actor, self::PERMISSIONS[$action]);
        $values = $this->normalizeIds($values);

        return $this->operation($actor, $action, $values, $requestKey, function (string $operationId) use ($actor, $action, $values): array {
            if ($action === 'warehouse') {
                $warehouse = isset($values['warehouse_id']) ? Warehouse::whereKey($values['warehouse_id'])->lockForUpdate()->firstOrFail() : new Warehouse;
                if ($warehouse->exists) {
                    $this->version($warehouse->version, $values['expected_version']);
                    abort_if(! $values['active'] && StockBalance::where('warehouse_id', $warehouse->id)->where(fn ($q) => $q->where('physical', '>', 0)->orWhere('reserved', '>', 0)->orWhere('control', '>', 0)->orWhere('rejected', '>', 0))->exists(), 409, 'Videz le dépôt avant de le désactiver.');
                }
                abort_if(Warehouse::where('code', $values['code'])->when($warehouse->exists, fn ($q) => $q->where('id', '!=', $warehouse->id))->exists(), 409, 'Ce code de dépôt existe déjà.');
                $before = $warehouse->exists ? ['code' => $warehouse->code, 'active' => $warehouse->active, 'version' => $warehouse->version] : null;
                $warehouse->fill(['code' => $values['code'], 'name' => $values['name'], 'active' => $values['active'], 'version' => $warehouse->exists ? $warehouse->version + 1 : 1])->save();
                $this->audit($actor, $action, $warehouse->id, $operationId, $before, ['code' => $warehouse->code, 'active' => $warehouse->active, 'version' => $warehouse->version]);

                return ['warehouse_id' => $warehouse->id, 'version' => $warehouse->version];
            }
            $warehouseIds = [$values['warehouse_id']];
            if ($action === 'transfer') {
                $warehouseIds[] = $values['destination_warehouse_id'];
                abort_if($warehouseIds[0] === $warehouseIds[1], 422, 'Choisissez deux dépôts différents.');
            }
            $this->lockWarehouses($warehouseIds);
            if ($action === 'location') {
                abort_if(WarehouseLocation::where('warehouse_id', $values['warehouse_id'])->where('code', $values['code'])->exists(), 409, 'Ce code existe dans ce dépôt.');
                $location = WarehouseLocation::create(array_intersect_key($values, array_flip(['warehouse_id', 'code', 'aisle', 'shelf', 'level'])));
                $this->audit($actor, $action, $location->id, $operationId, null, ['warehouse_id' => $location->warehouse_id, 'code' => $location->code]);

                return ['location_id' => $location->id];
            }
            Product::whereKey($values['product_id'])->lockForUpdate()->firstOrFail();
            sort($warehouseIds, SORT_STRING);
            $balances = [];
            foreach ($warehouseIds as $warehouseId) {
                $balances[$warehouseId] = $this->balance($warehouseId, (int) $values['product_id']);
            }
            $balance = $balances[$values['warehouse_id']];
            $this->version($balance->version, $values['expected_version']);
            if ($action === 'configure') {
                $locationId = $values['location_id'] ?? null;
                abort_if($locationId !== null && ! WarehouseLocation::whereKey($locationId)->where('warehouse_id', $balance->warehouse_id)->exists(), 422, 'Cet emplacement appartient à un autre dépôt.');
                $before = $this->snapshot($balance);
                $balance->fill(['location_id' => $locationId, 'threshold_quantity' => $values['threshold_quantity'], 'threshold_basis' => $values['threshold_basis'], 'version' => $balance->version + 1])->save();
                $this->refreshAlert($balance);
                $this->audit($actor, $action, $balance->id, $operationId, $before, $this->snapshot($balance));
            } elseif ($action === 'transfer') {
                $destination = $balances[$values['destination_warehouse_id']];
                $this->version($destination->version, $values['destination_expected_version']);
                abort_if($values['quantity'] > $balance->physical - $balance->reserved, 409, 'Quantité disponible insuffisante : les unités réservées ne sont pas transférables.');
                $this->move($actor, $operationId, $balance, 'transfer_out', ['physical' => -$values['quantity']], $values);
                $this->move($actor, $operationId, $destination, 'transfer_in', ['physical' => $values['quantity']], $values);
            } elseif ($action === 'inspect') {
                $this->move($actor, $operationId, $balance, 'inspection', ['control' => -$values['quantity'], $values['destination'] => $values['quantity']], $values);
            } else {
                $this->move($actor, $operationId, $balance, $action === 'receive' ? 'receipt' : 'adjustment', [$values['bucket'] => $values['quantity']], $values);
            }

            return ['balances' => array_map(fn (StockBalance $item): array => ['id' => $item->id, ...$this->snapshot($item)], array_values($balances))];
        });
    }

    /** Called by order confirmation inside its transaction, after locking/updating the order.
     * Quantities are base units, not packages. A shortage never makes the balance negative.
     *
     * @param  list<array{warehouse_id: string, product_id: int, line_id: string, quantity: int}>  $lines
     * @return array<string, mixed>
     */
    public function reserve(User $actor, int $orderId, int $orderVersion, array $lines, string $requestKey): array
    {
        $this->requireOrderTransaction();
        $lines = array_map(fn (array $line): array => $this->normalizeIds($line), $lines);
        $this->authorize($actor, 'ORDER_CONFIRM');
        $values = validator(['lines' => $lines], [
            'lines' => 'required|array|min:1|max:100', 'lines.*.warehouse_id' => 'required|uuid',
            'lines.*.product_id' => 'required|integer|min:1', 'lines.*.line_id' => 'required|uuid',
            'lines.*.quantity' => 'required|integer|min:1|max:2147483647',
        ])->validate();

        return $this->operation($actor, 'reserve', ['order_id' => $orderId, 'version' => $orderVersion, ...$values], $requestKey, function (string $operationId) use ($actor, $orderId, $orderVersion, $lines): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $this->version((int) $order->version, $orderVersion);
            abort_unless($order->status === 'CONFIRMEE', 409, 'La réservation exige une confirmation dans la même transaction.');
            abort_unless(CustomerProfile::where('user_id', $order->user_id)->where('approval', 'APPROUVE')->exists(), 409, 'Le compte client doit être approuvé.');
            $orderLines = collect(json_decode($order->lines, true, flags: JSON_THROW_ON_ERROR))->keyBy('id');
            $this->lockWarehouses(array_column($lines, 'warehouse_id'));
            $productIds = array_unique(array_column($lines, 'product_id'));
            sort($productIds, SORT_NUMERIC);
            foreach ($productIds as $id) {
                Product::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            usort($lines, fn (array $a, array $b): int => [$a['warehouse_id'], $a['product_id'], $a['line_id']] <=> [$b['warehouse_id'], $b['product_id'], $b['line_id']]);
            $result = [];
            foreach ($lines as $line) {
                $original = $orderLines->get($line['line_id']);
                abort_if($original === null || (int) $original['product_id'] !== (int) $line['product_id'], 422, 'La ligne ne correspond pas à cette commande.');
                $allocated = DB::table('stock_reservations')->where('order_id', $orderId)->where('line_id', $line['line_id'])->selectRaw('COALESCE(SUM(quantity - released), 0) AS units')->first()->units;
                $maximum = (int) $original['quantity'] * (int) $original['units_per_package'];
                abort_if((int) $allocated + $line['quantity'] > $maximum, 409, 'La réservation dépasse la quantité commandée.');
                $balance = $this->balance($line['warehouse_id'], (int) $line['product_id']);
                $quantity = min($line['quantity'], $balance->physical - $balance->reserved);
                $id = null;
                if ($quantity > 0) {
                    $id = (string) Str::uuid();
                    DB::table('stock_reservations')->insert(['id' => $id, 'order_id' => $orderId, 'line_id' => $line['line_id'], 'balance_id' => $balance->id, 'quantity' => $quantity, 'order_version' => $orderVersion, 'operation_id' => $operationId, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                    $this->move($actor, $operationId, $balance, 'reservation', ['reserved' => $quantity], ['reason' => 'Confirmation de commande', 'source_reference' => $order->number]);
                }
                $result[] = ['reservation_id' => $id, 'line_id' => $line['line_id'], 'warehouse_id' => $line['warehouse_id'], 'reserved' => $quantity, 'shortage' => $line['quantity'] - $quantity];
            }

            return ['allocations' => $result];
        });
    }

    /** Release is only valid in the same transaction as cancellation. It never restocks physical units.
     * @return array<string, mixed>
     */
    public function releaseCancelledOrder(User $actor, int $orderId, int $orderVersion, string $requestKey): array
    {
        $this->requireOrderTransaction();
        $this->authorize($actor, 'RESERVATION_CANCEL');

        return $this->operation($actor, 'release', ['order_id' => $orderId, 'version' => $orderVersion], $requestKey, function (string $operationId) use ($actor, $orderId, $orderVersion): array {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $this->version((int) $order->version, $orderVersion);
            abort_unless($order->status === 'ANNULEE', 409, 'Annulez la commande dans la même transaction avant de libérer ses réservations.');
            $reservations = DB::table('stock_reservations')->where('order_id', $orderId)->orderBy('balance_id')->orderBy('id')->get();
            $balanceIds = $reservations->pluck('balance_id')->unique();
            $stock = StockBalance::whereIn('id', $balanceIds)->get();
            $this->lockWarehouses($stock->pluck('warehouse_id')->all(), false);
            foreach ($stock->pluck('product_id')->unique()->sort()->values() as $id) {
                Product::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            foreach ($reservations as $reservation) {
                $remaining = $reservation->quantity - $reservation->released - $reservation->consumed;
                if ($remaining > 0) {
                    $balance = StockBalance::whereKey($reservation->balance_id)->lockForUpdate()->firstOrFail();
                    $this->move($actor, $operationId, $balance, 'release', ['reserved' => -$remaining], ['reason' => 'Annulation de commande', 'source_reference' => $order->number]);
                    DB::table('stock_reservations')->where('id', $reservation->id)->update(['released' => $reservation->released + $remaining, 'updated_at' => now('UTC')]);
                }
            }

            return ['order_id' => $orderId, 'released' => true];
        });
    }

    /** Consume reserved base units at the separately validated real departure.
     * The delivery orchestrator owns the surrounding transaction and batch locks.
     *
     * @param  list<array{line_id: string, quantity: int}>  $lines
     * @return array<string, mixed>
     */
    public function dispatchBatch(User $actor, int $orderId, string $warehouseId, array $lines, string $requestKey, string $deliveryNumber): array
    {
        $this->requireOrderTransaction();
        $this->authorize($actor, 'DELIVERY_DEPARTURE_CONFIRM');
        $warehouseId = Str::lower($warehouseId);
        $lines = array_map(fn (array $line): array => ['line_id' => Str::lower($line['line_id']), 'quantity' => (int) $line['quantity']], $lines);

        return $this->operation($actor, 'dispatch', ['order_id' => $orderId, 'warehouse_id' => $warehouseId, 'lines' => $lines, 'delivery_number' => $deliveryNumber], $requestKey, function (string $operationId) use ($actor, $orderId, $warehouseId, $lines, $deliveryNumber): array {
            DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $this->lockWarehouses([$warehouseId], false);
            $result = [];
            foreach ($lines as $line) {
                $remaining = $line['quantity'];
                abort_if($remaining < 1, 422, 'Une quantité préparée doit être positive.');
                $reservations = DB::table('stock_reservations as r')->join('stock_balances as b', 'b.id', '=', 'r.balance_id')
                    ->where('r.order_id', $orderId)->where('r.line_id', $line['line_id'])->where('b.warehouse_id', $warehouseId)
                    ->orderBy('r.created_at')->orderBy('r.id')->lockForUpdate()
                    ->get(['r.id', 'r.balance_id', 'r.quantity', 'r.released', 'r.consumed']);
                abort_if($reservations->sum(fn (object $reservation): int => (int) $reservation->quantity - (int) $reservation->released - (int) $reservation->consumed) < $remaining, 409, 'La quantité préparée dépasse la réservation active.');
                foreach ($reservations as $reservation) {
                    $available = (int) $reservation->quantity - (int) $reservation->released - (int) $reservation->consumed;
                    $consume = min($remaining, $available);
                    if ($consume === 0) {
                        continue;
                    }
                    $balance = StockBalance::whereKey($reservation->balance_id)->lockForUpdate()->firstOrFail();
                    $this->move($actor, $operationId, $balance, 'dispatch', ['physical' => -$consume, 'reserved' => -$consume], ['reason' => 'Départ réel validé', 'source_reference' => $deliveryNumber]);
                    DB::table('stock_reservations')->where('id', $reservation->id)->update(['consumed' => (int) $reservation->consumed + $consume, 'updated_at' => now('UTC')]);
                    $remaining -= $consume;
                    if ($remaining === 0) {
                        break;
                    }
                }
                $result[] = ['line_id' => $line['line_id'], 'quantity' => $line['quantity']];
            }

            return ['order_id' => $orderId, 'warehouse_id' => $warehouseId, 'delivery_number' => $deliveryNumber, 'lines' => $result];
        });
    }

    private function requireOrderTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Order and stock changes require one caller-owned transaction.');
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function operation(User $actor, string $action, array $values, string $key, callable $callback): array
    {
        abort_unless(Str::isUuid($key), 422, 'Idempotency-Key UUID requis.');
        $key = Str::lower($key);
        $hash = hash('sha256', json_encode($this->canonical($values), JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $action, $key, $hash, $callback): array {
                DB::table('stock_operations')->insertOrIgnore(['id' => (string) Str::uuid(), 'request_key' => $key, 'actor_id' => $actor->id, 'action' => $action, 'request_hash' => $hash, 'created_at' => now('UTC')]);
                $operation = DB::table('stock_operations')->where('request_key', $key)->lockForUpdate()->firstOrFail();
                abort_if((int) $operation->actor_id !== (int) $actor->id || $operation->action !== $action || ! hash_equals($operation->request_hash, $hash), 409, 'Cette clé est déjà utilisée pour une autre opération.');
                if ($operation->result !== null) {
                    return $this->canonical(json_decode($operation->result, true, flags: JSON_THROW_ON_ERROR));
                }
                $result = $callback($operation->id);
                $result['operation_id'] = $operation->id;
                $result = $this->canonical($result);
                DB::table('stock_operations')->where('id', $operation->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);

                return $result;
            }, 3);
        } catch (DeadlockException) {
            abort(409, 'Conflit de stock. Annulez la transaction complète puis réessayez.');
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [1062, 1205, 1213], true) || $exception->getCode() === '40001') {
                abort(409, 'Conflit de stock. Actualisez puis réessayez.');
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeIds(array $values): array
    {
        foreach (['warehouse_id', 'destination_warehouse_id', 'location_id', 'line_id'] as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $values[$field] = Str::lower($values[$field]);
            }
        }

        return $values;
    }

    /** @param array<string|int, mixed> $values
     * @return array<string|int, mixed>
     */
    private function canonical(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $values;
    }

    /** @param list<string> $ids */
    private function lockWarehouses(array $ids, bool $requireActive = true): void
    {
        $ids = array_unique($ids);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $warehouse = Warehouse::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($requireActive && ! $warehouse->active, 409, 'Ce dépôt est désactivé.');
        }
    }

    private function balance(string $warehouseId, int $productId): StockBalance
    {
        $balance = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first();

        return $balance ?? StockBalance::create(['warehouse_id' => $warehouseId, 'product_id' => $productId]);
    }

    private function version(int $current, int $expected): void
    {
        abort_unless($current === $expected, 409, 'Le stock a changé. Actualisez avant de recommencer.');
    }

    /** @param array<string, int> $deltas
     * @param  array<string, mixed>  $source
     */
    private function move(User $actor, string $operationId, StockBalance $balance, string $kind, array $deltas, array $source): void
    {
        $before = $this->snapshot($balance);
        $changes = [];
        foreach (['physical', 'reserved', 'control', 'rejected'] as $bucket) {
            $delta = $deltas[$bucket] ?? 0;
            $quantity = (int) $balance->{$bucket} + $delta;
            abort_if($quantity < 0 || $quantity > 2147483647, 409, 'Quantité insuffisante ou hors limite.');
            $balance->{$bucket} = $quantity;
            $changes[$bucket.'_delta'] = $delta;
        }
        abort_if($balance->physical < $balance->reserved, 409, 'Le stock vendable ne peut pas être inférieur au réservé.');
        $balance->version++;
        $balance->save();
        $after = $this->snapshot($balance);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'operation_id' => $operationId, 'balance_id' => $balance->id, 'kind' => $kind, 'reason' => $source['reason'], 'source_reference' => $source['source_reference'], ...$changes, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
        $this->refreshAlert($balance);
        $this->audit($actor, $kind, $balance->id, $operationId, $before, $after);
    }

    /** @return array<string, mixed> */
    private function snapshot(StockBalance $balance): array
    {
        return ['warehouse_id' => $balance->warehouse_id, 'product_id' => $balance->product_id, 'physical' => (int) $balance->physical, 'reserved' => (int) $balance->reserved, 'available' => (int) $balance->physical - (int) $balance->reserved, 'control' => (int) $balance->control, 'rejected' => (int) $balance->rejected, 'version' => $balance->version, 'location_id' => $balance->location_id, 'threshold_quantity' => $balance->threshold_quantity, 'threshold_basis' => $balance->threshold_basis];
    }

    private function refreshAlert(StockBalance $balance): void
    {
        $observed = $balance->physical - $balance->reserved;
        if ($balance->threshold_quantity !== null && $balance->threshold_basis !== null && $observed <= $balance->threshold_quantity) {
            $values = ['basis' => $balance->threshold_basis, 'threshold' => $balance->threshold_quantity, 'observed_quantity' => $observed];
            if (DB::table('stock_alerts')->where('active_balance_id', $balance->id)->exists()) {
                DB::table('stock_alerts')->where('active_balance_id', $balance->id)->update($values);
            } else {
                DB::table('stock_alerts')->insert(['id' => (string) Str::uuid(), 'balance_id' => $balance->id, 'active_balance_id' => $balance->id, ...$values, 'opened_at' => now('UTC')]);
            }
        } else {
            DB::table('stock_alerts')->where('active_balance_id', $balance->id)->update(['active_balance_id' => null, 'resolved_at' => now('UTC')]);
        }
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, string $id, string $operationId, ?array $before, array $after): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'request_id' => app(AuditContext::class)->requestId ?? $operationId, 'action' => 'stock.'.$action, 'object_type' => 'stock', 'object_id' => $id, 'result' => 'SUCCESS', 'changes' => ['before' => $before, 'after' => $after, 'operation_id' => $operationId], 'occurred_at' => now('UTC')]);
    }
}
