<?php

declare(strict_types=1);

namespace App\Domain\Review;

use Illuminate\Support\Facades\DB;

/**
 * The measurements that decide whether automation is allowed.
 *
 * Auto-approval is not a config toggle someone flips because the queue is
 * long. It is earned per subject: only once reviewers have demonstrably been
 * agreeing with the machine, over a meaningful sample, does the platform stop
 * asking them. This class is what turns that policy into a number.
 */
final class ReviewerMetrics
{
    /** Sample size below which no agreement rate is trustworthy. */
    public const MIN_SAMPLE = 500;

    /** Reviewers may override at most this share before automation is unsafe. */
    public const MAX_OVERRIDE_RATE = 0.05;

    /**
     * @return array{
     *   sample: int, approved: int, rejected: int, edited: int,
     *   override_rate: float, agreement_rate: float,
     *   median_seconds: float|null, per_hour: float|null,
     *   auto_approve_eligible: bool
     * }
     */
    public function forScope(?string $organizationId = null, ?string $reviewerId = null): array
    {
        $rows = DB::table('reviews')
            ->whereNotNull('reviewed_at')
            ->when($organizationId, $this->visibleTo(...))
            ->when($reviewerId, fn ($q) => $q->where('reviewed_by', $reviewerId))
            ->selectRaw("
                count(*) AS sample,
                count(*) FILTER (WHERE decision = 'approved') AS approved,
                count(*) FILTER (WHERE decision = 'rejected') AS rejected,
                count(*) FILTER (WHERE was_edited) AS edited,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY time_spent_seconds)
                    FILTER (WHERE time_spent_seconds IS NOT NULL) AS median_seconds
            ")
            ->first();

        $sample = (int) ($rows->sample ?? 0);
        $approved = (int) ($rows->approved ?? 0);
        $rejected = (int) ($rows->rejected ?? 0);
        $edited = (int) ($rows->edited ?? 0);
        $median = $rows->median_seconds !== null ? (float) $rows->median_seconds : null;

        // An override is the machine being wrong in either direction: the
        // reviewer rejected what it kept, or had to correct it before keeping.
        $overrides = $rejected + $edited;
        $overrideRate = $sample > 0 ? round($overrides / $sample, 4) : 0.0;

        return [
            'sample' => $sample,
            'approved' => $approved,
            'rejected' => $rejected,
            'edited' => $edited,
            'override_rate' => $overrideRate,
            'agreement_rate' => round(1 - $overrideRate, 4),
            'median_seconds' => $median,
            'per_hour' => $median !== null && $median > 0 ? round(3600 / $median, 1) : null,
            'auto_approve_eligible' => $sample >= self::MIN_SAMPLE
                && $overrideRate < self::MAX_OVERRIDE_RATE,
        ];
    }

    /**
     * The same visibility rule the rest of the platform uses: your own
     * organization's work plus platform-owned content.
     *
     * Scoping strictly to organization_id would hide every platform question
     * a content reviewer works on — they are organization_id NULL — and report
     * a reviewer who has done hundreds of decisions as having done none.
     */
    private function visibleTo(mixed $query, string $organizationId): void
    {
        $query->where(
            static fn ($q) => $q->whereNull('organization_id')
                ->orWhere('organization_id', $organizationId)
        );
    }

    /**
     * Queue health. A queue growing faster than it is drained is the signal to
     * hire reviewers, not to loosen thresholds.
     *
     * @return array{pending: int, in_review: int, oldest_hours: float|null, decided_24h: int}
     */
    public function queueHealth(?string $organizationId = null): array
    {
        $row = DB::table('reviews')
            ->when($organizationId, $this->visibleTo(...))
            ->selectRaw("
                count(*) FILTER (WHERE status = 'pending') AS pending,
                count(*) FILTER (WHERE status = 'in_review') AS in_review,
                min(created_at) FILTER (WHERE status IN ('pending','in_review')) AS oldest,
                count(*) FILTER (WHERE reviewed_at > now() - interval '24 hours') AS decided_24h
            ")
            ->first();

        $oldest = $row->oldest ?? null;

        return [
            'pending' => (int) ($row->pending ?? 0),
            'in_review' => (int) ($row->in_review ?? 0),
            'oldest_hours' => $oldest === null
                ? null
                : round((time() - strtotime((string) $oldest)) / 3600, 1),
            'decided_24h' => (int) ($row->decided_24h ?? 0),
        ];
    }
}
