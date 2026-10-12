<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Audit\AuditRecorder;
use App\Models\Question;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The question review state machine.
 *
 *   AI_GENERATED → AI_VALIDATED → PENDING_REVIEW → APPROVED
 *                                               ↘ REJECTED
 *                                               ↘ REVISION_REQUIRED → PENDING_REVIEW
 *   APPROVED → REVISION_REQUIRED   (post-publish error report)
 *   APPROVED → RETIRED             (syllabus change, expired current affairs)
 *
 * Two rules run through everything here:
 *   - Nothing is deleted. A rejected or retired question stays attached to the
 *     attempts that used it, so a student's history remains explainable.
 *   - Every change is versioned before it is applied.
 */
final class ReviewWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'AI_GENERATED' => ['AI_VALIDATED', 'PENDING_REVIEW', 'REJECTED'],
        'AI_VALIDATED' => ['PENDING_REVIEW', 'APPROVED', 'REJECTED'],
        'PENDING_REVIEW' => ['APPROVED', 'REJECTED', 'REVISION_REQUIRED'],
        'REVISION_REQUIRED' => ['PENDING_REVIEW', 'REJECTED'],
        'APPROVED' => ['REVISION_REQUIRED', 'RETIRED'],
        'REJECTED' => ['PENDING_REVIEW'],
        'RETIRED' => [],
    ];

    /** Opens a review item for a question that has cleared the gauntlet. */
    public function enqueue(Question $question, int $priority = 5): Review
    {
        return Review::query()->updateOrCreate(
            ['reviewable_type' => Question::class, 'reviewable_id' => $question->getKey()],
            [
                'organization_id' => $question->organization_id,
                'status' => 'pending',
                // Higher-quality candidates first: a reviewer's first hour
                // should be spent on questions most likely to be keepers.
                'priority' => $priority,
                'machine_status' => $question->status,
            ],
        );
    }

    /**
     * Claims the next item for a reviewer.
     *
     * The claim is a short lease rather than a permanent assignment, so an
     * abandoned session returns its work to the queue automatically.
     */
    public function claimNext(User $reviewer, ?string $organizationId = null): ?Review
    {
        return DB::transaction(function () use ($reviewer, $organizationId): ?Review {
            $review = Review::query()
                ->claimable()
                ->when(
                    $organizationId,
                    fn ($q) => $q->where(fn ($q) => $q->whereNull('organization_id')
                        ->orWhere('organization_id', $organizationId)),
                )
                ->orderByDesc('priority')
                ->orderBy('created_at')
                // Two reviewers hitting "next" simultaneously must not get the
                // same question; the row lock makes the claim atomic.
                ->lockForUpdate()
                ->first();

            if ($review === null) {
                return null;
            }

            $review->forceFill([
                'status' => 'in_review',
                'assigned_to' => $reviewer->getKey(),
                'assigned_at' => now(),
                'claim_expires_at' => now()->addMinutes(Review::CLAIM_MINUTES),
            ])->save();

            return $review;
        });
    }

    /**
     * @param  array<string, mixed>  $edits  optional corrections applied before approval
     */
    public function decide(
        Review $review,
        User $reviewer,
        string $decision,
        ?string $reason = null,
        array $edits = [],
        ?int $timeSpentSeconds = null,
    ): Question {
        if ($review->isClaimedBySomeoneElse($reviewer->getKey())) {
            throw new RuntimeException('This item is currently claimed by another reviewer.');
        }

        $question = Question::query()->findOrFail($review->reviewable_id);

        $status = match ($decision) {
            'approve' => 'APPROVED',
            'reject' => 'REJECTED',
            'revision' => 'REVISION_REQUIRED',
            default => throw new RuntimeException("Unknown review decision: {$decision}"),
        };

        return DB::transaction(function () use (
            $review, $reviewer, $question, $status, $decision, $reason, $edits, $timeSpentSeconds
        ): Question {
            $edited = $edits !== [];

            if ($edited) {
                $this->applyEdits($question, $edits, $reviewer, $reason);
            }

            $this->transition($question, $status, $reviewer);

            $review->forceFill([
                'status' => match ($decision) {
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    default => 'revision_required',
                },
                'decision' => match ($decision) {
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    default => 'revision_required',
                },
                'decision_reason' => $reason,
                'was_edited' => $edited,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'time_spent_seconds' => $timeSpentSeconds,
                'claim_expires_at' => null,
            ])->save();

            return $question->fresh();
        });
    }

    public function transition(Question $question, string $to, ?User $actor = null): void
    {
        $from = (string) $question->status;
        $allowed = self::TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException("Illegal question transition {$from} → {$to}.");
        }

        $question->forceFill(array_filter([
            'status' => $to,
            'approved_by' => $to === 'APPROVED' ? $actor?->getKey() : null,
            'approved_at' => $to === 'APPROVED' ? now() : null,
        ], static fn ($v): bool => $v !== null))->save();

        // Recorded inside the caller's transaction: if the transition commits,
        // the record of who made it commits with it.
        $this->audit->record('question.'.mb_strtolower($to), $question, [
            'from' => $from,
            'to' => $to,
        ], $actor?->getKey());

        // PostgreSQL reports an UPDATE filtered out by RLS as a success that
        // matched no rows, so a permissions gap would otherwise return 200
        // while nothing changed. Verify the write actually landed.
        if ((string) $question->fresh()?->status !== $to) {
            throw new RuntimeException(
                "Transition {$from} → {$to} did not persist for question {$question->getKey()}. "
                .'This usually means row-level security rejected the write.'
            );
        }
    }

    /**
     * Applies reviewer corrections, snapshotting first.
     *
     * @param  array<string, mixed>  $edits
     */
    private function applyEdits(Question $question, array $edits, User $reviewer, ?string $reason): void
    {
        $this->snapshot($question, $reviewer, $reason, $this->changeType($edits));

        $master = $question->master;

        if ($master !== null) {
            $master->forceFill(array_filter([
                'question_text' => $edits['question_text'] ?? null,
                'option_a' => $edits['options']['A'] ?? null,
                'option_b' => $edits['options']['B'] ?? null,
                'option_c' => $edits['options']['C'] ?? null,
                'option_d' => $edits['options']['D'] ?? null,
                'explanation' => $edits['explanation'] ?? null,
            ], static fn ($v): bool => $v !== null))->save();
        }

        if (isset($edits['correct_option'])) {
            // Changing the key invalidates every translation's validation, so
            // translations are marked stale rather than silently kept.
            foreach ($question->options as $option) {
                $option->forceFill([
                    'is_correct' => $option->option_key === $edits['correct_option'],
                ])->save();
            }

            $question->translations()
                ->where('is_master', false)
                ->update(['translation_status' => 'STALE']);
        }

        if (isset($edits['difficulty'])) {
            $question->forceFill(['difficulty' => $edits['difficulty']])->save();
        }

        if (isset($edits['canonical_topic_id'])) {
            $question->forceFill(['canonical_topic_id' => $edits['canonical_topic_id']])->save();
        }

        $question->forceFill(['current_version' => $question->current_version + 1])->save();

        // Editing the master text invalidates translations derived from it.
        if (isset($edits['question_text']) || isset($edits['options'])) {
            $question->translations()
                ->where('is_master', false)
                ->update(['translation_status' => 'STALE']);
        }
    }

    private function snapshot(Question $question, User $actor, ?string $reason, string $changeType): void
    {
        $question->loadMissing(['options', 'translations']);

        DB::table('question_versions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'question_id' => $question->getKey(),
            'version' => $question->current_version,
            'snapshot' => json_encode([
                'status' => $question->status,
                'difficulty' => $question->difficulty,
                'canonical_topic_id' => $question->canonical_topic_id,
                'options' => $question->options->map(fn ($o) => [
                    'key' => $o->option_key, 'is_correct' => $o->is_correct,
                ])->all(),
                'translations' => $question->translations->map(fn ($t) => [
                    'language_id' => $t->language_id,
                    'question_text' => $t->question_text,
                    'options' => $t->optionMap(),
                    'explanation' => $t->explanation,
                ])->all(),
            ], JSON_THROW_ON_ERROR),
            'change_type' => $changeType,
            'change_reason' => $reason,
            'changed_by' => $actor->getKey(),
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $edits */
    private function changeType(array $edits): string
    {
        return match (true) {
            isset($edits['correct_option']) => 'answer_change',
            isset($edits['question_text']), isset($edits['options']) => 'text_edit',
            isset($edits['difficulty']) => 'difficulty_change',
            isset($edits['canonical_topic_id']) => 'topic_change',
            default => 'edit',
        };
    }
}
