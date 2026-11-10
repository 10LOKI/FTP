<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\AccessPermissionCatalog;
use Illuminate\Database\Seeder;

class SalesReviewPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE'] as $code) {
            Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => AccessPermissionCatalog::LABELS[$code]]);
        }
    }
}
