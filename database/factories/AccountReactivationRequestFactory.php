<?php

namespace Database\Factories;

use App\Models\AccountReactivationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountReactivationRequest> */
class AccountReactivationRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory()->state(['inactivity_suspended_at' => now('UTC'), 'inactivity_version' => 1]),
            'active_user_id' => fn (array $attributes): int => $attributes['user_id'],
            'suspension_version' => 1, 'version' => 1, 'status' => 'PENDING', 'request_reason' => 'Please review my access.'];
    }
}
