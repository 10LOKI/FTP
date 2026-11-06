<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Permission> */
class PermissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PERMISSION_????_########'),
            'module' => 'access',
            'label' => fake()->words(2, true),
            'is_protected' => false,
        ];
    }
}
