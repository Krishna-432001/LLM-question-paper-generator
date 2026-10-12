<?php

declare(strict_types=1);

namespace App\Actions\Jobs;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Jobs\JobStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\AiJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Creates the ledger row and dispatches the transport job, in that order and
 * in one transaction.
 *
 * Doing it the other way round loses the race: a fast worker can pick the job
 * up before the row it is supposed to update exists.
 */
final class DispatchTrackedJobAction
{
    /**
     * @param  class-string<ShouldQueue>  $jobClass
     * @param  array<string, mixed>  $input
     */
    public function execute(
        string $type,
        string $jobClass,
        array $input = [],
        ?string $organizationId = null,
        ?string $userId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $idempotencyKey = null,
        ?string $parentJobId = null,
    ): AiJob {
        $queue = config("examforge.jobs.queues.{$type}", 'default');

        // An identical request inside the idempotency window returns the
        // original job instead of spending tokens twice. This single unique
        // index prevents more duplicate AI spend than any cache.
        if ($idempotencyKey !== null) {
            $existing = TenantContext::withoutTenancy(
                fn () => AiJob::query()->where('idempotency_key', $idempotencyKey)->first()
            );

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $type, $jobClass, $input, $organizationId, $userId,
            $subjectType, $subjectId, $idempotencyKey, $parentJobId, $queue
        ): AiJob {
            $job = AiJob::query()->create([
                'parent_job_id' => $parentJobId,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'type' => $type,
                'queue' => $queue,
                // Set explicitly rather than left to the column DEFAULT:
                // Eloquent does not read defaults back, so the returned model
                // would carry a null status and every caller reading it breaks.
                'status' => JobStatus::Queued,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'input' => $input,
                'idempotency_key' => $idempotencyKey,
                'max_attempts' => config('examforge.jobs.default_max_attempts'),
                'queued_at' => now(),
            ]);

            // Every dispatched job is recorded, because generation is the one
            // action here that spends money and "who ordered this?" has to be
            // answerable from the log rather than from memory.
            app(AuditRecorder::class)->record('job.dispatched', $job, [
                'type' => $type,
                'queue' => $queue,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'input' => $input,
            ], $userId);

            // afterCommit: the worker must never see a row that a rollback
            // is about to remove.
            dispatch(new $jobClass($job->getKey()))->onQueue($queue)->afterCommit();

            return $job;
        });
    }
}
