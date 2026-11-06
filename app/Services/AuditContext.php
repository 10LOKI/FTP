<?php

namespace App\Services;

use Illuminate\Support\Str;

class AuditContext
{
    public bool $active = false;

    public int $events = 0;

    public ?string $requestId = null;

    /** @var list<array<string, mixed>> */
    public array $failures = [];

    public function reset(bool $active = false): void
    {
        $this->active = $active;
        $this->requestId = $active ? (string) Str::uuid() : null;
        $this->events = 0;
        $this->failures = [];
    }
}
