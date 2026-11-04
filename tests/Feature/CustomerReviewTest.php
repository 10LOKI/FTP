<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CustomerReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_the_queue_or_customer_details(): void
    {
        $profile = CustomerProfile::factory()->create();

        $this->getJson('/api/v1/customer-registrations')->assertUnauthorized();
        $this->getJson('/api/v1/customer-registrations/'.$profile->id)->assertUnauthorized();
    }

    public function test_authenticated_users_without_approval_permission_are_denied(): void
    {
        $profile = CustomerProfile::factory()->create();
        $this->actingAs($profile->user, 'web');

        $this->getJson('/api/v1/customer-registrations')->assertForbidden();
        $this->getJson('/api/v1/customer-registrations/'.$profile->id)->assertForbidden();
    }

    public function test_queue_contains_only_pending_customers_and_is_paginated(): void
    {
        $this->signInReviewer();
        CustomerProfile::factory()->count(3)->create();
        CustomerProfile::factory()->create(['approval' => 'APPROUVE']);
        CustomerProfile::factory()->create(['approval' => 'REFUSE']);

        $first = $this->getJson('/api/v1/customer-registrations?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $second = $this->getJson('/api/v1/customer-registrations?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertNotContains($second->json('data.0.customerId'), array_column($first->json('data'), 'customerId'));
        foreach ($first->json('data') as $row) {
            $this->assertSame('EN_ATTENTE', $row['approval']);
        }
    }

    public function test_queue_can_filter_by_customer_type(): void
    {
        $this->signInReviewer();
        CustomerProfile::factory()->create();
        $company = CustomerProfile::factory()->create([
            'type' => 'ENTREPRISE', 'company_name' => 'Example Parts', 'responsible_name' => 'Manager',
        ]);

        $this->getJson('/api/v1/customer-registrations?type=ENTREPRISE')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.customerId', $company->id);
    }

    public function test_detail_exposes_only_review_fields(): void
    {
        $this->signInReviewer();
        $profile = CustomerProfile::factory()->create(['internal_notes' => 'Private note']);

        $this->getJson('/api/v1/customer-registrations/'.$profile->id)->assertOk()
            ->assertExactJson(['data' => [
                'customerId' => $profile->id,
                'type' => $profile->type,
                'approval' => 'EN_ATTENTE',
                'version' => 1,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
                'phone' => $profile->phone,
                'address' => $profile->address,
                'city' => $profile->city,
                'country' => $profile->country,
                'company_name' => null,
                'responsible_name' => null,
                'registered_at' => $profile->created_at->toISOString(),
            ]]);
    }

    public function test_invalid_or_unsupported_filters_are_rejected(): void
    {
        $this->signInReviewer();

        $this->getJson('/api/v1/customer-registrations?per_page=101')->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/customer-registrations?approval=APPROUVE')->assertUnprocessable()
            ->assertJsonValidationErrors('approval');
    }

    public function test_missing_customer_returns_not_found(): void
    {
        $this->signInReviewer();

        $this->getJson('/api/v1/customer-registrations/00000000-0000-0000-0000-000000000001')->assertNotFound();
    }

    public function test_permission_is_rechecked_for_subsequent_requests(): void
    {
        $reviewer = User::factory()->create();
        $this->actingAs($reviewer, 'web');
        Gate::define('CUSTOMER_APPROVE', fn (User $user): bool => $user->id === $reviewer->id);
        $this->getJson('/api/v1/customer-registrations')->assertOk();

        Gate::define('CUSTOMER_APPROVE', fn (User $user): bool => false);
        $this->getJson('/api/v1/customer-registrations')->assertForbidden();
    }

    private function signInReviewer(): void
    {
        $reviewer = User::factory()->create();
        $this->actingAs($reviewer, 'web');
        Gate::define('CUSTOMER_APPROVE', fn (User $user): bool => $user->id === $reviewer->id);
    }
}
