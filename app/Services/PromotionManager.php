<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PromotionManager
{
    /** @param array<string, mixed> $values */
    public function store(int $productId, array $values, string $key, User $actor): Promotion
    {
        Gate::forUser($actor)->authorize('PROMOTION_MANAGE');
        $fingerprint = hash('sha256', json_encode(['product_id' => $productId] + $values, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($productId, $values, $key, $actor, $fingerprint): Promotion {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $existing = Promotion::query()->where('created_by', $actor->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Cette clé de création a déjà été utilisée pour une autre demande.');

                return $existing;
            }
            $this->validateEnabled($product, $values);
            $promotion = new Promotion($values);
            $promotion->forceFill(['product_id' => $productId, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint])->save();
            $this->record($actor, 'promotion.created', $promotion->id, null, $this->snapshot($promotion));

            return $promotion;
        }, 3);
    }

    /** @param array<string, mixed> $values */
    public function update(Promotion $promotion, array $values, int $expectedVersion, User $actor): Promotion
    {
        Gate::forUser($actor)->authorize('PROMOTION_MANAGE');

        return DB::transaction(function () use ($promotion, $values, $expectedVersion, $actor): Promotion {
            $product = Product::query()->lockForUpdate()->findOrFail($promotion->product_id);
            $locked = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            abort_if($locked->version !== $expectedVersion, 409, 'Cette promotion a changé. Actualisez la liste.');
            $this->validateEnabled($product, $values, $locked->id);
            $before = $this->snapshot($locked);
            $locked->fill($values);
            if ($locked->isDirty()) {
                $locked->version++;
                $locked->updated_by = $actor->id;
                $locked->save();
                $this->record($actor, 'promotion.updated', $locked->id, $before, $this->snapshot($locked));
            }

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $values */
    private function validateEnabled(Product $product, array $values, ?string $except = null): void
    {
        if ($values['state'] !== 'enabled') {
            return;
        }
        PricingPolicy::requireApproved();
        if ($values['timezone'] !== config('pricing.timezone')) {
            throw ValidationException::withMessages(['timezone' => 'Le fuseau métier approuvé est '.config('pricing.timezone').'.']);
        }
        $this->validatePrice($product->sale_price, $values['kind'], $values['value']);
        if (Promotion::query()->where('product_id', $product->id)->where('state', 'enabled')
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->where('starts_at', '<', $values['ends_at'])->where('ends_at', '>', $values['starts_at'])->lockForUpdate()->first(['id']) !== null) {
            throw ValidationException::withMessages(['starts_at' => 'Une autre promotion activée couvre déjà cette période pour ce produit.']);
        }
    }

    public function validateBasePriceChange(Product $product, string $newPrice): void
    {
        foreach (Promotion::query()->where('product_id', $product->id)->where('state', 'enabled')->where('ends_at', '>', now('UTC'))->get() as $promotion) {
            try {
                $this->validatePrice($newPrice, $promotion->kind, $promotion->value);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['sale_price' => 'Ce tarif rendrait une promotion activée invalide. Désactivez ou corrigez cette offre avant de modifier le tarif.']);
            }
        }
    }

    private function validatePrice(string|float|int|null $base, string $kind, string $value): void
    {
        if ($base === null) {
            throw ValidationException::withMessages(['value' => 'Un tarif de base est requis avant activation.']);
        }
        $baseUnits = PriceAmount::minorUnits((string) $base);
        $offer = $kind === 'fixed_price' ? PriceAmount::minorUnits($value) : PriceAmount::discounted($baseUnits, $value);
        if ($offer >= $baseUnits) {
            throw ValidationException::withMessages(['value' => 'Le prix promotionnel arrondi doit être strictement inférieur au tarif de base.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Promotion $promotion): array
    {
        return $promotion->only(['product_id', 'title', 'kind', 'value', 'starts_at', 'ends_at', 'timezone', 'state', 'version']);
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     */
    public function record(User $actor, string $action, string $objectId, ?array $before, array $after): void
    {
        AuditEvent::create([
            'actor_id' => $actor->id, 'request_id' => (string) Str::uuid(), 'action' => $action,
            'object_type' => Str::startsWith($action, 'promotion.') ? 'promotion' : 'pricing', 'object_id' => $objectId,
            'result' => 'SUCCESS', 'reason' => null, 'changes' => ['before' => $before, 'after' => $after], 'occurred_at' => now('UTC'),
        ]);
    }
}
