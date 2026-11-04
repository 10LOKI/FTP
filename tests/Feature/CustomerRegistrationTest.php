<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_creates_a_pending_customer_without_logging_in(): void
    {
        $response = $this->postJson('/api/v1/customer-registrations', $this->payload());

        $profile = CustomerProfile::sole();
        $user = User::sole();
        $response->assertCreated()->assertExactJson(['data' => [
            'customerId' => $profile->id,
            'type' => 'PARTICULIER',
            'approval' => 'EN_ATTENTE',
            'message' => 'Votre demande est en attente d’approbation. L’accès aux commandes reste bloqué.',
        ]]);
        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame('EN_ATTENTE', $profile->approval);
        $this->assertNull($profile->customer_code);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($this->payload()['password'], $user->password));
        $this->assertNotSame($this->payload()['password'], $user->password);
        $this->assertGuest();
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->getJson('/api/v1/products/1')->assertUnauthorized();
    }

    public function test_company_details_are_saved_without_requiring_ice(): void
    {
        $this->postJson('/api/v1/customer-registrations', array_replace($this->payload(), [
            'type' => 'ENTREPRISE',
            'company_name' => 'Example Parts',
            'responsible_name' => 'Example Manager',
        ]))->assertCreated()->assertJsonPath('data.type', 'ENTREPRISE');

        $this->assertDatabaseHas('customer_profiles', [
            'type' => 'ENTREPRISE',
            'company_name' => 'Example Parts',
            'responsible_name' => 'Example Manager',
            'approval' => 'EN_ATTENTE',
        ]);
    }

    public function test_repeated_registration_does_not_create_another_account(): void
    {
        $this->postJson('/api/v1/customer-registrations', $this->payload())->assertCreated();
        $this->postJson('/api/v1/customer-registrations', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_profiles', 1);
    }

    public function test_existing_employee_email_cannot_be_registered_as_a_customer(): void
    {
        User::factory()->create(['email' => $this->payload()['email']]);

        $this->postJson('/api/v1/customer-registrations', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    #[DataProvider('internalFields')]
    public function test_injected_internal_fields_leave_no_account(string $field): void
    {
        $this->postJson('/api/v1/customer-registrations', $this->payload() + [$field => 'injected'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    public static function internalFields(): array
    {
        return [['roles'], ['approval'], ['customer_code'], ['internal_notes'], ['user_id']];
    }

    public function test_invalid_company_input_leaves_no_account(): void
    {
        $this->postJson('/api/v1/customer-registrations', array_replace($this->payload(), ['type' => 'ENTREPRISE']))
            ->assertUnprocessable()->assertJsonValidationErrors(['company_name', 'responsible_name']);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    public function test_profile_failure_rolls_back_the_user_creation(): void
    {
        CustomerProfile::creating(function (): void {
            throw new RuntimeException('Simulated profile storage failure');
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/customer-registrations', $this->payload());
            $this->fail('Expected the simulated storage failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated profile storage failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    public function test_profile_serialization_hides_internal_notes(): void
    {
        $profile = CustomerProfile::factory()->create(['internal_notes' => 'Private staff note']);

        $this->assertArrayNotHasKey('internal_notes', $profile->toArray());
    }

    public function test_registration_audit_is_attributed_to_a_visitor_without_secrets(): void
    {
        $this->withHeader('X-Request-ID', 'untrusted-client-value')
            ->postJson('/api/v1/customer-registrations', $this->payload())->assertCreated();

        $event = AuditEvent::sole();
        $this->assertNull($event->actor_id);
        $this->assertTrue(Str::isUuid($event->request_id));
        $this->assertSame(CustomerProfile::sole()->id, $event->object_id);
        $this->assertSame('customer.registration', $event->action);
        $this->assertSame('SUCCESS', $event->result);
        $this->assertSame(['before' => null, 'after' => ['type' => 'PARTICULIER', 'approval' => 'EN_ATTENTE']], $event->changes);
        $this->assertNotNull($event->occurred_at);
        $this->assertStringNotContainsString($this->payload()['password'], $event->toJson());
        $this->assertStringNotContainsString(User::sole()->password, $event->toJson());
        $this->assertStringNotContainsString($this->payload()['email'], $event->toJson());
    }

    public function test_rejected_registration_is_audited_without_injected_content(): void
    {
        $this->postJson('/api/v1/customer-registrations', $this->payload() + ['roles' => 'injected-secret'])
            ->assertUnprocessable();

        $event = AuditEvent::sole();
        $this->assertSame('REFUSED', $event->result);
        $this->assertSame('validation_failed', $event->reason);
        $this->assertNull($event->object_id);
        $this->assertNull($event->changes);
        $this->assertStringNotContainsString('injected-secret', $event->toJson());
    }

    public function test_rendered_storage_failure_is_audited_after_rollback(): void
    {
        CustomerProfile::creating(function (): void {
            throw new RuntimeException('Simulated storage failure');
        });

        $this->postJson('/api/v1/customer-registrations', $this->payload())->assertStatus(500);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_profiles', 0);
        $this->assertSame('FAILED', AuditEvent::sole()->result);
    }

    public function test_success_audit_failure_prevents_partial_registration(): void
    {
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->result === 'SUCCESS') {
                throw new RuntimeException('Simulated success audit failure');
            }
        });

        $this->postJson('/api/v1/customer-registrations', $this->payload())->assertStatus(500);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_profiles', 0);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertSame('FAILED', AuditEvent::sole()->result);
    }

    public function test_registration_retry_records_one_success_and_one_refusal(): void
    {
        $this->postJson('/api/v1/customer-registrations', $this->payload())->assertCreated();
        $this->postJson('/api/v1/customer-registrations', $this->payload())->assertUnprocessable();

        $this->assertSame(1, AuditEvent::where('result', 'SUCCESS')->count());
        $this->assertSame(1, AuditEvent::where('result', 'REFUSED')->count());
        $this->assertSame(2, AuditEvent::distinct()->count('request_id'));
    }

    public function test_audit_model_rejects_updates(): void
    {
        $this->postJson('/api/v1/customer-registrations', $this->payload())->assertCreated();
        $this->expectException(\LogicException::class);

        AuditEvent::sole()->update(['result' => 'FAILED']);
    }

    public function test_audit_model_rejects_deletions(): void
    {
        $this->postJson('/api/v1/customer-registrations', $this->payload())->assertCreated();
        $this->expectException(\LogicException::class);

        AuditEvent::sole()->delete();
    }

    public function test_passwords_exceeding_bcrypt_input_limits_are_rejected_before_storage(): void
    {
        foreach ([str_repeat('a', 73), str_repeat('é', 37), "customer\0password"] as $password) {
            $this->postJson('/api/v1/customer-registrations', array_replace($this->payload(), ['password' => $password]))
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customer_profiles', 0);
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'type' => 'PARTICULIER',
            'name' => 'Example Customer',
            'phone' => '+212 600 000 000',
            'email' => 'customer@example.com',
            'password' => 'Customer-password-123',
            'address' => '10 Example Street',
            'city' => 'Casablanca',
            'country' => 'Morocco',
        ];
    }
}
