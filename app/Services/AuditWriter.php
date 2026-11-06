<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditWriter
{
    /** @param array<string, mixed> $event */
    public function record(array $event): AuditEvent
    {
        return AuditEvent::create($event);
    }

    /** @param array<string, mixed> $failure
     */
    public function transaction(callable $operation, array $failure): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('The audited transaction must own the outer transaction boundary.');
        }
        try {
            return DB::transaction($operation);
        } catch (Throwable $exception) {
            $refused = $exception instanceof AuthenticationException || $exception instanceof AuthorizationException || $exception instanceof ValidationException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
            $this->record(array_replace($failure, [
                'request_id' => $failure['request_id'] ?? (string) Str::uuid(), 'changes' => null,
                'result' => $refused ? 'REFUSED' : 'FAILED', 'reason' => $refused ? 'operation_refused' : 'operation_failed',
                'occurred_at' => now('UTC'),
            ]));
            throw $exception;
        }
    }
}
