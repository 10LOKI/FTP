<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('DEP-####'), 'name' => fake()->city(), 'active' => true, 'version' => 1];
    }
}
