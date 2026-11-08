<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockBalance> */
class StockBalanceFactory extends Factory
{
    public function definition(): array
    {
        return ['warehouse_id' => Warehouse::factory(), 'product_id' => Product::factory(), 'physical' => 0, 'reserved' => 0, 'control' => 0, 'rejected' => 0, 'version' => 0];
    }
}
