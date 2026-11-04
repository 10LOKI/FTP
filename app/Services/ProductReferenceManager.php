<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\ProductCrossReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductReferenceManager
{
    /** @param array{reference: string, brand?: ?string} $values */
    public function store(Product $product, array $values, User $actor): ProductCrossReference
    {
        Gate::forUser($actor)->authorize('REFERENCE_MANAGE');

        return DB::transaction(function () use ($product, $values, $actor): ProductCrossReference {
            $this->lockProduct($product);
            $existing = $this->association($product, $values)->orderBy('id')->first();
            if ($existing !== null) {
                return $existing;
            }

            $reference = new ProductCrossReference(['reference' => $values['reference'], 'brand' => $values['brand'] ?? '']);
            $reference->product_id = $product->id;
            $reference->save();
            $this->record($actor, 'product.reference.created', $reference, null, $this->snapshot($reference));

            return $reference;
        });
    }

    /** @param array{reference: string, brand?: ?string} $values */
    public function update(Product $product, int $id, array $values, int $version, User $actor): ProductCrossReference
    {
        Gate::forUser($actor)->authorize('REFERENCE_MANAGE');

        return DB::transaction(function () use ($product, $id, $values, $version, $actor): ProductCrossReference {
            $this->lockProduct($product);
            $reference = $this->find($product, $id);
            $this->checkVersion($reference, $version);
            if ($this->association($product, $values)->where('id', '!=', $id)->exists()) {
                throw ValidationException::withMessages(['reference' => 'Cette référence et cette source sont déjà associées au produit.']);
            }

            $before = $this->snapshot($reference);
            $reference->fill(['reference' => $values['reference'], 'brand' => $values['brand'] ?? '']);
            if ($reference->isDirty(['reference', 'brand'])) {
                $reference->verified_at = null;
                $reference->version++;
                $reference->save();
                $this->record($actor, 'product.reference.updated', $reference, $before, $this->snapshot($reference));
            }

            return $reference;
        });
    }

    public function verify(Product $product, int $id, int $version, User $actor): void
    {
        Gate::forUser($actor)->authorize('REFERENCE_MANAGE');

        DB::transaction(function () use ($product, $id, $version, $actor): void {
            $this->lockProduct($product);
            $reference = $this->find($product, $id);
            $this->checkVersion($reference, $version);
            if ($reference->verified_at === null) {
                $before = $this->snapshot($reference);
                $reference->verified_at = now('UTC');
                $reference->save();
                $this->record($actor, 'product.reference.verified', $reference, $before, $this->snapshot($reference));
            }
        });
    }

    public function delete(Product $product, int $id, int $version, User $actor): void
    {
        Gate::forUser($actor)->authorize('REFERENCE_MANAGE');

        DB::transaction(function () use ($product, $id, $version, $actor): void {
            $this->lockProduct($product);
            $reference = $this->find($product, $id);
            $this->checkVersion($reference, $version);
            $before = $this->snapshot($reference);
            $reference->delete();
            $this->record($actor, 'product.reference.deleted', $reference, $before, null);
        });
    }

    public function updateOriginal(Product $product, string $reference, string $expected, User $actor): Product
    {
        Gate::forUser($actor)->authorize('REFERENCE_MANAGE');

        return DB::transaction(function () use ($product, $reference, $expected, $actor): Product {
            $locked = $this->lockProduct($product);
            abort_if($locked->original_reference !== $expected, 409, 'La référence originale a changé. Actualisez le produit.');
            if ($reference !== $locked->original_reference) {
                $before = ['reference' => $locked->original_reference];
                $locked->original_reference = $reference;
                $locked->save();
                $this->record($actor, 'product.reference.original_updated', $locked, $before, ['reference' => $reference]);
            }

            return $locked;
        });
    }

    /** @param array<int, array{brand: string, reference: string}> $values */
    public function replace(Product $product, array $values, User $actor): void
    {
        DB::transaction(function () use ($product, $values, $actor): void {
            $this->lockProduct($product);
            $this->replaceLocked($product, $values, $actor);
        });
    }

    /** @param array<int, array{brand: string, reference: string}> $values */
    private function replaceLocked(Product $product, array $values, User $actor): void
    {
        $current = ProductCrossReference::query()->where('product_id', $product->id)->get();
        $identity = fn (array $value): string => json_encode([$value['brand'], $value['reference']], JSON_THROW_ON_ERROR);
        $wanted = collect($values)->map($identity)->unique()->sort()->values()->all();
        $existing = $current->map(fn (ProductCrossReference $reference): string => $identity($reference->only(['brand', 'reference'])))
            ->unique()->sort()->values()->all();
        if ($wanted === $existing) {
            return;
        }
        Gate::forUser($actor)->authorize('REFERENCE_MANAGE');

        $retained = [];
        foreach (collect($values)->unique(fn (array $value): string => $value['brand'].'|'.ProductReferenceNormalizer::searchKey($value['reference'])) as $value) {
            $reference = $this->association($product, $value)->orderBy('id')->first();
            if ($reference === null) {
                $reference = $this->store($product, $value, $actor);
            } elseif ($reference->reference !== $value['reference'] || $reference->brand !== $value['brand']) {
                $reference = $this->update($product, $reference->id, $value, $reference->version, $actor);
            }
            $retained[] = $reference->id;
        }
        foreach ($current->whereNotIn('id', $retained) as $removed) {
            $this->delete($product, $removed->id, $removed->version, $actor);
        }
    }

    private function lockProduct(Product $product): Product
    {
        return Product::query()->lockForUpdate()->findOrFail($product->id);
    }

    private function find(Product $product, int $id): ProductCrossReference
    {
        return ProductCrossReference::query()->where('product_id', $product->id)->findOrFail($id);
    }

    /** @param array{reference: string, brand?: ?string} $values
     * @return Builder<ProductCrossReference>
     */
    private function association(Product $product, array $values): Builder
    {
        return ProductCrossReference::query()->where('product_id', $product->id)
            ->where('brand', $values['brand'] ?? '')
            ->where('reference_search_key', ProductReferenceNormalizer::searchKey($values['reference']));
    }

    private function checkVersion(ProductCrossReference $reference, int $version): void
    {
        abort_if($reference->version !== $version, 409, 'La référence a changé. Actualisez les références.');
    }

    /** @return array{brand: string, reference: string, verified_at: ?string, version: int} */
    private function snapshot(ProductCrossReference $reference): array
    {
        return [
            'brand' => $reference->brand, 'reference' => $reference->reference,
            'verified_at' => $reference->verified_at?->toIso8601String(), 'version' => $reference->version,
        ];
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(User $actor, string $action, Product|ProductCrossReference $subject, ?array $before, ?array $after): void
    {
        AuditEvent::create([
            'actor_id' => $actor->getAuthIdentifier(), 'request_id' => (string) Str::uuid(),
            'action' => $action, 'object_type' => $subject instanceof Product ? 'product' : 'product_cross_reference',
            'object_id' => (string) $subject->id, 'result' => 'SUCCESS', 'reason' => null,
            'changes' => ['before' => $before, 'after' => $after], 'occurred_at' => now('UTC'),
        ]);
    }
}
