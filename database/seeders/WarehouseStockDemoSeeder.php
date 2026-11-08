<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Seeder;
use LogicException;

class WarehouseStockDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Stock demonstration data is restricted to local/testing.');
        }
        foreach (['DEMO-A', 'DEMO-B'] as $code) {
            $warehouse = Warehouse::firstOrCreate(['code' => $code], ['name' => 'Dépôt de démonstration '.$code]);
            WarehouseLocation::firstOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'A-01'], ['aisle' => 'A', 'shelf' => '01', 'level' => '1']);
        }
    }
}
