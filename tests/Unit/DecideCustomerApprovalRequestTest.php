<?php

namespace Tests\Unit;

use App\Http\Requests\DecideCustomerApprovalRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DecideCustomerApprovalRequestTest extends TestCase
{
    public function test_approval_can_generate_a_code_or_preserve_a_supplied_code(): void
    {
        $this->assertSame('APPROUVE', $this->validateDecision($this->payload())['decision']);
        $this->assertSame('000-ABC/12', $this->validateDecision($this->payload() + ['customer_code' => '000-ABC/12'])['customer_code']);
    }

    public function test_refusal_with_reason_and_version_is_accepted(): void
    {
        $this->assertSame('REFUSE', $this->validateDecision(array_replace($this->payload(), ['decision' => 'REFUSE']))['decision']);
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_decisions_are_rejected(array $change, string $field): void
    {
        try {
            $this->validateDecision(array_replace($this->payload(), $change));
            $this->fail('Expected decision validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidDecisions(): array
    {
        return [
            [['decision' => null], 'decision'],
            [['decision' => 'SUSPENDU'], 'decision'],
            [['version' => null], 'version'],
            [['version' => 0], 'version'],
            [['version' => 1.5], 'version'],
            [['reason' => null], 'reason'],
            [['reason' => '   '], 'reason'],
            [['reason' => str_repeat('a', 2001)], 'reason'],
            [['customer_code' => null], 'customer_code'],
            [['customer_code' => str_repeat('a', 256)], 'customer_code'],
            [['decision' => 'REFUSE', 'customer_code' => 'CLI-000001'], 'customer_code'],
            [['decided_by' => 123], 'decided_by'],
            [['decided_at' => '2026-01-01'], 'decided_at'],
            [['roles' => null], 'roles'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateDecision(array $payload): array
    {
        $request = DecideCustomerApprovalRequest::create('/api/v1/customers/example/approval-decisions', 'POST', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request->validated();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['decision' => 'APPROUVE', 'version' => 1, 'reason' => 'Customer details reviewed'];
    }
}
