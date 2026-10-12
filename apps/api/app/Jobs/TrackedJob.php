<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Jobs\JobStatus;
use App\Domain\Jobs\PermanentFailure;
use App\Jobs\Middleware\SkipIfCancelled;
use App\Jobs\Middleware\TenantAware;
use App\Models\AiJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Base class for every queued unit of work.
 *
 * Subclasses implement execute() only. Status transitions, tenancy, retries,
 * cancellation and cost roll-up are handled here exactly once, so no job can
 * forget them.
 */
abstract class TrackedJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Not readonly. Laravel restores a queued job through
     * SerializesModels::__unserialize, which writes properties by reflection
     * from the *child* class scope — and PHP forbids initialising a parent's
     * readonly property from there. Marking this readonly makes every queued
     * job in the system fail on unserialize, before handle() is ever reached.
     */
    public function __construct(public string $aiJobId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new TenantAware($this->aiJobId),
            new SkipIfCancelled($this->aiJobId),
            (new WithoutOverlapping($this->aiJobId))->expireAfter(3600),
        ];
    }

    public function handle(): void
    {
        $job = AiJob::query()->findOrFail($this->aiJobId);

        // Idempotency: a redelivered message for finished work is a no-op,
        // never a second execution.
        if ($job->isFinished()) {
            return;
        }

        $job->markProcessing();

        try {
            $output = $this->execute($job);
            $job->refresh();

            if ($job->status !== JobStatus::Cancelled) {
                $job->markCompleted($output);
            }
        } catch (Throwable $e) {
            // No retry changes an infected file or a non-PDF. Fail now rather
            // than spend the backoff schedule reaching the same verdict.
            if ($e instanceof PermanentFailure) {
                $job->markFailed($e);
                $this->fail($e);

                return;
            }

            if ($this->attempts() < $this->tries) {
                $job->markRetryScheduled($e);
                $this->release($this->backoffFor($this->attempts()));

                return;
            }

            $job->markFailed($e);

            throw $e;
        }
    }

    /**
     * The actual work. Return value becomes ai_jobs.output.
     *
     * @return array<string, mixed>
     */
    abstract protected function execute(AiJob $job): array;

    public function failed(?Throwable $e): void
    {
        if ($e !== null) {
            AiJob::query()->find($this->aiJobId)?->markFailed($e);
        }
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return config('examforge.jobs.backoff_seconds');
    }

    private function backoffFor(int $attempt): int
    {
        $schedule = config('examforge.jobs.backoff_seconds');

        return $schedule[$attempt - 1] ?? end($schedule);
    }
}
