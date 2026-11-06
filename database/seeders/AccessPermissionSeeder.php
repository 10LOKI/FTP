<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\AccessPermissionCatalog;
use Illuminate\Database\Seeder;

class AccessPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AccessPermissionCatalog::LABELS as $code => $label) {
            Permission::firstOrCreate(['code' => $code], ['module' => 'access', 'label' => $label]);
        }
    }
}
