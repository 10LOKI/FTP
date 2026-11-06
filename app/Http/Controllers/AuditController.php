<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditFilterRequest;
use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use App\Services\AuditWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AuditController extends Controller
{
    public function page(): Response
    {
        abort_unless(Gate::any(['AUDIT_READ', 'AUDIT_EXPORT']), 403);

        return response()->view('audit')->header('Cache-Control', 'private, no-store');
    }

    public function index(AuditFilterRequest $request): JsonResponse
    {
        $page = $request->apply(AuditEvent::query())->orderByDesc('occurred_at')->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 20));

        return response()->json([
            'data' => AuditEventResource::collection($page->items())->resolve($request),
            'meta' => ['per_page' => $page->perPage(), 'next_cursor' => $page->nextCursor()?->encode(),
                'previous_cursor' => $page->previousCursor()?->encode()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function export(AuditFilterRequest $request, AuditWorkbook $workbook): BinaryFileResponse|Response
    {
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
        }
        $file = DB::transaction(fn (): array => $workbook->create($request->apply(AuditEvent::query())));
        try {
            AuditEvent::create([
                'actor_id' => $request->user()->id, 'request_id' => (string) Str::uuid(),
                'action' => 'audit.export', 'object_type' => 'audit_log', 'object_id' => null,
                'result' => 'SUCCESS', 'reason' => 'workbook_prepared',
                'changes' => ['after' => ['month' => $request->validated('month'), 'row_count' => $file['count']]], 'occurred_at' => now('UTC'),
            ]);
        } catch (Throwable $exception) {
            unlink($file['path']);
            throw $exception;
        }

        return response()->download($file['path'], 'audit-'.$request->validated('month').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Audit-Export-Count' => (string) $file['count'],
        ])->deleteFileAfterSend();
    }
}
