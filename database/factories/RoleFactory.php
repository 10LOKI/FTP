<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('ROLE_????_########'),
            'label' => fake()->words(2, true),
            'is_protected' => false,
            'version' => 1,
        ];
    }
}
