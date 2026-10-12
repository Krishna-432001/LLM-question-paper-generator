<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the audit trail.
 *
 * Read-only by construction: the table revokes UPDATE and DELETE for the
 * application role, so there is no write path to expose here even if someone
 * wanted one.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action').'%'))
            ->when($request->filled('actor_user_id'), fn ($q) => $q->where('actor_user_id', $request->string('actor_user_id')))
            ->when($request->filled('auditable_id'), fn ($q) => $q->where('auditable_id', $request->string('auditable_id')))
            ->when($request->filled('since'), fn ($q) => $q->where('created_at', '>=', $request->date('since')))
            ->orderByDesc('created_at')
            ->paginate(min(200, $request->integer('per_page', 50)));

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $log): array => [
                'id' => $log->getKey(),
                'action' => $log->action,
                'actor_user_id' => $log->actor_user_id,
                'actor_role' => $log->actor_role,
                'subject_type' => $log->auditable_type !== null ? class_basename($log->auditable_type) : null,
                'subject_id' => $log->auditable_id,
                'changes' => $log->changes,
                'ip' => $log->ip,
                'request_id' => $log->request_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * What has happened to one record, oldest first.
     *
     * This is the question an audit trail actually gets asked: not "what
     * happened today" but "how did this question end up approved".
     */
    public function forSubject(string $subjectId): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('auditable_id', $subjectId)
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $logs->map(fn (AuditLog $log): array => [
                'action' => $log->action,
                'actor_user_id' => $log->actor_user_id,
                'changes' => $log->changes,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
