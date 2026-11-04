<?php

namespace Tests\Unit;

use App\Http\Requests\CustomerLoginRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerLoginRequestTest extends TestCase
{
    public function test_code_or_email_identifier_is_accepted_without_changing_punctuation(): void
    {
        $this->assertSame('000-ABC/12', $this->validateLogin(['identifier' => '000-ABC/12', 'password' => 'existing-password'])['identifier']);
        $this->assertSame('customer@example.com', $this->validateLogin(['identifier' => 'customer@example.com', 'password' => 'existing-password'])['identifier']);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_injected_fields_are_rejected(array $changes, string $field): void
    {
        try {
            $this->validateLogin(array_replace(['identifier' => 'CLI-000001', 'password' => 'existing-password'], $changes));
            $this->fail('Expected invalid login input.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidInputs(): array
    {
        return [
            [['identifier' => null], 'identifier'],
            [['identifier' => []], 'identifier'],
            [['identifier' => str_repeat('a', 256)], 'identifier'],
            [['password' => null], 'password'],
            [['password' => []], 'password'],
            [['password' => str_repeat('a', 4097)], 'password'],
            [['user_id' => 1], 'user_id'],
            [['approval' => 'APPROUVE'], 'approval'],
            [['roles' => null], 'roles'],
            [['remember' => true], 'remember'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateLogin(array $payload): array
    {
        $request = CustomerLoginRequest::create('/api/v1/login', 'POST', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request->validated();
    }
}
