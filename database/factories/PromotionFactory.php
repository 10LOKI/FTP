<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(), 'title' => fake()->sentence(3),
            'kind' => 'percentage', 'value' => '10.00', 'state' => 'draft',
            'starts_at' => now('UTC')->subHour(), 'ends_at' => now('UTC')->addHour(), 'timezone' => 'UTC',
            'created_by' => User::factory(), 'updated_by' => User::factory(),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes): array => ['state' => 'enabled']);
    }
}
