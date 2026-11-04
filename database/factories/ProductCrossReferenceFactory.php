<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCrossReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductCrossReference>
 */
class ProductCrossReferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'brand' => fake()->company(),
            'reference' => fake()->bothify('CROSS-#####'),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => ['verified_at' => now('UTC')]);
    }
}
