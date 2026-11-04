<?php

namespace Database\Seeders;

use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class CustomerDiscountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || getenv('FTP_PRICING_DEMO') !== '1' || getenv('FTP_PRICING_DEMO_CUSTOMER') !== '1') {
            return;
        }
        $profile = CustomerProfile::query()->where('customer_code', 'FTP18-DEMO-CUSTOMER')->whereHas('user', fn ($query) => $query->where('email', 'ftp18-fixture-customer@example.test'))->first();
        $actor = User::query()->where('email', 'ftp18-fixture-author@example.test')->first();
        if ($profile !== null && $actor !== null && ! CustomerDiscount::query()->where('customer_profile_id', $profile->id)->exists()) {
            CustomerDiscount::factory()->for($profile)->create(['percentage' => '10.00', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        }
    }
}
