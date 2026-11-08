<?php

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WarehouseLocation> */
class WarehouseLocationFactory extends Factory
{
    public function definition(): array
    {
        return ['warehouse_id' => Warehouse::factory(), 'code' => fake()->unique()->bothify('LOC-####'), 'aisle' => 'A', 'shelf' => '1', 'level' => '2'];
    }
}
