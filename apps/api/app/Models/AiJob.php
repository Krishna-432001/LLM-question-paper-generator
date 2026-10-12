<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Jobs\JobStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single job ledger for all async work.
 *
 * Laravel's `jobs` table is transport and is deleted on retry. This row is the
 * durable, user-visible, costed, resumable record: it survives retries, tracks
 * progress, aggregates token spend and supports cooperative cancellation.
 */
class AiJob extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'input' => 'array',
            'output' => 'array',
            'cost_inr' => 'decimal:4',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_job_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_job_id');
    }

    public function invocations(): HasMany
    {
        return $this->hasMany(AiInvocation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn('status', [
            JobStatus::Queued->value,
            JobStatus::Processing->value,
            JobStatus::Validating->value,
            JobStatus::RetryScheduled->value,
        ]);
    }

    public function markProcessing(?string $message = null): void
    {
        $this->forceFill([
            'status' => JobStatus::Processing,
            'started_at' => $this->started_at ?? now(),
            'attempts' => $this->attempts + 1,
            'progress_message' => $message,
        ])->save();
    }

    public function progressTo(int $percent, ?string $message = null): void
    {
        $this->forceFill([
            'progress' => max(0, min(100, $percent)),
            'progress_message' => $message ?? $this->progress_message,
        ])->save();
    }

    public function markValidating(?string $message = null): void
    {
        $this->forceFill([
            'status' => JobStatus::Validating,
            'progress_message' => $message,
        ])->save();
    }

    /** @param  array<string, mixed>  $output */
    public function markCompleted(array $output = []): void
    {
        $this->forceFill([
            'status' => JobStatus::Completed,
            'progress' => 100,
            'output' => $output ?: $this->output,
            'completed_at' => now(),
            'duration_ms' => $this->started_at
                ? (int) now()->diffInMilliseconds($this->started_at, absolute: true)
                : null,
        ])->save();
    }

    public function markFailed(Throwable $e, ?string $code = null): void
    {
        $this->forceFill([
            'status' => JobStatus::Failed,
            'error_code' => $code ?? class_basename($e),
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'error_trace' => mb_substr($e->getTraceAsString(), 0, 8000),
            'completed_at' => now(),
        ])->save();
    }

    public function markRetryScheduled(Throwable $e): void
    {
        $this->forceFill([
            'status' => JobStatus::RetryScheduled,
            'error_code' => class_basename($e),
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();
    }

    public function cancel(): void
    {
        $this->forceFill([
            'status' => JobStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();
    }

    public function isCancelled(): bool
    {
        return $this->status === JobStatus::Cancelled;
    }

    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    /** Roll a child's token spend up into this job and its ancestors. */
    public function recordUsage(int $inputTokens, int $outputTokens, float $costInr): void
    {
        DB::table($this->getTable())
            ->where('id', $this->getKey())
            ->update([
                'input_tokens' => DB::raw("input_tokens + {$inputTokens}"),
                'output_tokens' => DB::raw("output_tokens + {$outputTokens}"),
                'cost_inr' => DB::raw('cost_inr + '.number_format($costInr, 4, '.', '')),
                'updated_at' => now(),
            ]);

        $this->parent?->recordUsage($inputTokens, $outputTokens, $costInr);
    }
}
