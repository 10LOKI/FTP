<?php

namespace Database\Seeders;

use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesReviewDemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment('local', 'testing')
            && DB::connection()->getDatabaseName() === 'ftp_cart_tests', 403, 'Use the isolated ftp_cart_tests database only.');
        DB::transaction(function (): void {
            if (User::where('email', 'ftp21-agent@example.test')->exists()) {
                return;
            }
            DB::table('pricing_settings')->where('id', 1)->update(['currency' => 'MAD', 'tax_basis' => 'TTC']);
            $this->call(CustomerOrderDemoSeeder::class);
            foreach (['agent', 'other-agent', 'reader'] as $name) {
                $actor = User::factory()->create(['name' => 'FTP-21 fictif '.$name,
                    'email' => 'ftp21-'.$name.'@example.test', 'password' => 'ftp21-isolated-demo-only', 'access_version' => 1]);
                $role = Role::factory()->create();
                foreach ($name === 'reader' ? ['ORDER_REVIEW'] : ['ORDER_REVIEW', 'ORDER_REVIEW_MANAGE'] as $code) {
                    $permission = Permission::firstOrCreate(['code' => $code], ['module' => 'sales', 'label' => $code]);
                    $role->permissions()->attach($permission);
                }
                $actor->roles()->attach($role, ['assigned_by' => $actor->id, 'assigned_at' => now('UTC')]);
            }
            $customerId = CustomerProfile::where('customer_code', 'CLI-FTP-DEMO')->value('user_id');
            $recordedBy = User::where('email', 'ftp21-agent@example.test')->value('id');
            DB::table('customer_finance_accounts')->insert(['customer_id' => $customerId, 'blocked' => false, 'version' => 2]);
            DB::table('customer_payment_terms')->insert(['id' => (string) Str::uuid(), 'customer_id' => $customerId,
                'version' => 2, 'mode' => 'cheque', 'label' => 'Conditions fictives pour recette isolée',
                'recorded_by' => $recordedBy, 'reason' => 'Fixture uniquement, aucune approbation réelle.', 'created_at' => now('UTC')]);
        });
    }
}
