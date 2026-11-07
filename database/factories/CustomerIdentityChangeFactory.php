<?php

namespace Database\Factories;

use App\Models\CustomerIdentityChange;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerIdentityChange> */
class CustomerIdentityChangeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'active_customer_profile_id' => fn (array $attributes): string => $attributes['customer_profile_id'],
            'profile_version' => 1, 'version' => 1, 'status' => 'PENDING',
            'name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'type' => 'PARTICULIER', 'company_name' => null, 'responsible_name' => null,
        ];
    }
}
