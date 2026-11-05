<?php

namespace Database\Factories;

use App\Models\CustomerDiscount;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerDiscount> */
class CustomerDiscountFactory extends Factory
{
    public function definition(): array
    {
        return ['customer_profile_id' => CustomerProfile::factory(), 'percentage' => '10.00', 'created_by' => User::factory(), 'updated_by' => User::factory()];
    }
}
