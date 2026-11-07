<?php

namespace Database\Factories;

use App\Models\CustomerAddressChange;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddressChange> */
class CustomerAddressChangeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'active_customer_profile_id' => fn (array $attributes): string => $attributes['customer_profile_id'],
            'profile_version' => 1, 'version' => 1, 'status' => 'PENDING',
            'address' => fake()->streetAddress(), 'city' => fake()->city(), 'country' => fake()->country(),
        ];
    }
}
