<?php

namespace Tests\Unit;

use App\Http\Requests\RegisterCustomerRequest;
use Illuminate\Validation\PresenceVerifierInterface;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterCustomerRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setEmailMatches(0);
    }

    public function test_individual_can_submit_the_required_contact_fields(): void
    {
        $data = $this->validateRegistration($this->validIndividual());

        $this->assertSame('PARTICULIER', $data['type']);
        $this->assertSame('+212 600 000 000', $data['phone']);
        $this->assertSame('Casablanca', $data['city']);
    }

    public function test_company_requires_its_name_and_responsible_person_but_not_ice(): void
    {
        $data = $this->validateRegistration(array_replace($this->validIndividual(), [
            'type' => 'ENTREPRISE',
            'company_name' => 'Example Parts',
            'responsible_name' => 'Company Manager',
        ]));

        $this->assertSame('Example Parts', $data['company_name']);
        $this->assertSame('Company Manager', $data['responsible_name']);
        $this->assertArrayNotHasKey('ice', $data);
    }

    #[DataProvider('requiredFields')]
    public function test_missing_contact_fields_are_rejected(string $field): void
    {
        $payload = $this->validIndividual();
        unset($payload[$field]);

        $this->assertRejectedField($payload, $field);
    }

    public static function requiredFields(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'type', 'name', 'phone', 'email', 'password', 'address', 'city', 'country',
        ]);
    }

    public function test_company_cannot_omit_its_specific_fields(): void
    {
        $payload = array_replace($this->validIndividual(), ['type' => 'ENTREPRISE']);

        try {
            $this->validateRegistration($payload);
            $this->fail('Expected company fields to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_name', $exception->errors());
            $this->assertArrayHasKey('responsible_name', $exception->errors());
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected(string $field, mixed $value): void
    {
        $this->assertRejectedField(array_replace($this->validIndividual(), [$field => $value]), $field);
    }

    public static function invalidInputs(): array
    {
        return [
            ['type', 'ADMIN'],
            ['email', 'invalid-email'],
            ['email', str_repeat('a', 256).'@example.com'],
            ['password', 'short'],
            ['phone', ['+212600000000']],
            ['name', str_repeat('a', 256)],
            ['city', str_repeat('a', 256)],
            ['country', ['Morocco']],
            ['company_name', 'Unexpected company for an individual'],
            ['responsible_name', 'Unexpected manager for an individual'],
        ];
    }

    #[DataProvider('internalFields')]
    public function test_unexpected_fields_are_rejected_even_when_null(string $field): void
    {
        $this->assertRejectedField($this->validIndividual() + [$field => null], $field);
        $this->assertRejectedField($this->validIndividual() + [$field => 'injected'], $field);
    }

    public static function internalFields(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'role', 'roles', 'permissions', 'approval', 'status', 'customer_code',
            'internal_notes', 'user_id', 'email_verified_at', 'is_admin', 'unknown_field',
        ]);
    }

    public function test_an_existing_email_is_rejected(): void
    {
        $this->setEmailMatches(1);

        $this->assertRejectedField($this->validIndividual(), 'email');
    }

    private function setEmailMatches(int $count): void
    {
        $presence = Mockery::mock(PresenceVerifierInterface::class);
        $presence->shouldReceive('getCount')->withAnyArgs()->andReturn($count);
        $this->app['validator']->setPresenceVerifier($presence);
    }

    /** @param array<string, mixed> $payload */
    private function assertRejectedField(array $payload, string $field): void
    {
        try {
            $this->validateRegistration($payload);
            $this->fail('Expected a validation error for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateRegistration(array $payload): array
    {
        $request = RegisterCustomerRequest::create('/api/v1/customer-registrations', 'POST', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request->validated();
    }

    /** @return array<string, string> */
    private function validIndividual(): array
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
