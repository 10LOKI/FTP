<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\AccessPermissionCatalog;
use Illuminate\Database\Seeder;

class BillingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['INVOICE_PREVIEW', 'INVOICE_ISSUE', 'INVOICE_READ'] as $code) {
            Permission::firstOrCreate(['code' => $code], ['module' => 'billing', 'label' => AccessPermissionCatalog::LABELS[$code]]);
        }
    }
}
