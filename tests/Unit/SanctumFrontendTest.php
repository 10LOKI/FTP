<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class SanctumFrontendTest extends TestCase
{
    public function test_local_app_and_react_origins_are_recognized(): void
    {
        foreach (['http://localhost:8090', 'http://localhost:5173'] as $origin) {
            $request = Request::create('/api/v1/user');
            $request->headers->set('Origin', $origin);

            $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
        }
    }

    public function test_untrusted_and_lookalike_origins_are_not_recognized(): void
    {
        foreach (['https://example.com', 'http://localhost:8090.evil.example'] as $origin) {
            $request = Request::create('/api/v1/user');
            $request->headers->set('Origin', $origin);

            $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
        }
    }

    public function test_requests_without_a_frontend_origin_are_not_stateful(): void
    {
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend(Request::create('/api/v1/user')));
    }
}
