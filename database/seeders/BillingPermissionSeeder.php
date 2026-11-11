<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\AccessPermissionCatalog;
use Illuminate\Database\Seeder;

class BillingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['INVOICE_PREVIEW', 'INVOICE_ISSUE', 'INVOICE_READ', 'PAYMENT_READ', 'PAYMENT_RECORD', 'PAYMENT_CORRECT', 'PAYMENT_UNBLOCK'] as $code) {
            Permission::firstOrCreate(['code' => $code], ['module' => 'billing', 'label' => AccessPermissionCatalog::LABELS[$code]]);
        }
    }
}
