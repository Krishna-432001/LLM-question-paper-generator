<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The universal async contract. Every long-running operation in the platform
 * returns a job id that resolves here, so the frontend has one progress
 * component rather than one per feature.
 */
final class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jobs = $this->visible($request)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->whereNull('parent_job_id')
            ->latest('queued_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $jobs->map($this->shape(...))->all()]);
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = $this->visible($request)->with('children')->findOrFail($jobId);

        return response()->json([
            'data' => $this->shape($job) + [
                'children' => $job->children->map($this->shape(...))->all(),
                'output' => $job->output,
                'error' => $job->error_code === null ? null : [
                    'code' => $job->error_code,
                    'message' => $job->error_message,
                ],
            ],
        ]);
    }

    public function cancel(Request $request, string $jobId): JsonResponse
    {
        $job = $this->visible($request)->findOrFail($jobId);

        abort_if($job->isFinished(), 409, 'This job has already finished.');

        $job->cancel();

        return response()->json(['data' => $this->shape($job->fresh())]);
    }

    /**
     * Staff see the organization's jobs; a student sees their own. A job's
     * input and output can name someone else's upload and what was read
     * from it, which is theirs.
     *
     * @return Builder<AiJob>
     */
    private function visible(Request $request): Builder
    {
        $user = $request->user();

        return AiJob::query()->when(
            ! ($user->isPlatformAdmin() || $user->can('sources.view')),
            fn (Builder $q) => $q->where('user_id', $user->getKey()),
        );
    }

    /** @return array<string, mixed> */
    private function shape(AiJob $job): array
    {
        return [
            'id' => $job->getKey(),
            'type' => $job->type,
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'progress' => $job->progress,
            'progress_message' => $job->progress_message,
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'duration_ms' => $job->duration_ms,
            'usage' => [
                'input_tokens' => $job->input_tokens,
                'output_tokens' => $job->output_tokens,
                'cost_inr' => (float) $job->cost_inr,
            ],
        ];
    }
}
