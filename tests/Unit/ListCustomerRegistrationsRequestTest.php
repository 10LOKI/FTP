<?php

namespace Tests\Unit;

use App\Http\Requests\ListCustomerRegistrationsRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListCustomerRegistrationsRequestTest extends TestCase
{
    public function test_empty_query_uses_controller_defaults(): void
    {
        $this->assertSame([], $this->validateQuery([]));
    }

    public function test_supported_filters_are_accepted(): void
    {
        $data = $this->validateQuery(['page' => '2', 'per_page' => '100', 'type' => 'ENTREPRISE']);

        $this->assertSame('ENTREPRISE', $data['type']);
        $this->assertSame('100', $data['per_page']);
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_filters_are_rejected(array $query, string $field): void
    {
        try {
            $this->validateQuery($query);
            $this->fail('Expected an invalid query error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidQueries(): array
    {
        return [
            [['page' => 0], 'page'],
            [['page' => 'invalid'], 'page'],
            [['per_page' => 101], 'per_page'],
            [['per_page' => 0], 'per_page'],
            [['type' => 'ADMIN'], 'type'],
            [['approval' => 'APPROUVE'], 'approval'],
            [['sort' => 'internal_notes'], 'sort'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function validateQuery(array $query): array
    {
        $request = ListCustomerRegistrationsRequest::create('/api/v1/customer-registrations', 'GET', $query);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request->validated();
    }
}
