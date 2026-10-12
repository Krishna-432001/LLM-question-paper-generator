<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use App\Domain\Questions\DTOs\GenerationPlan;
use App\Models\Question;
use App\Models\QuestionGenerationTarget;
use Illuminate\Support\Collection;

/**
 * Decides how many questions actually need generating.
 *
 * Asking for fifty when forty-three already exist orders fifty, pays for
 * fifty, and then throws forty-three away at the dedup stage — the cost is
 * incurred on the way in, before anything can be rejected. This class turns
 * "I want fifty" into "order seven".
 *
 * The subtraction is deliberately conservative about work already in flight.
 * Questions awaiting review are counted against the target by default, because
 * an admin who generates twice before reviewing once would otherwise order the
 * same shortfall twice and double a review queue that is already the real
 * bottleneck. That is overridable, and both numbers are always reported, so the
 * decision is visible rather than assumed.
 */
final class TargetPlanner
{
    /**
     * Statuses that count as work already done or in flight.
     *
     * REJECTED is deliberately absent: a rejected question is a gap, not a
     * holding. Counting it would leave a topic permanently short by however
     * many bad drafts it once produced.
     */
    private const IN_FLIGHT = ['AI_VALIDATED', 'PENDING_REVIEW'];

    /**
     * Plans an explicit request: "give me N for this topic".
     */
    public function planRequest(
        int $requested,
        string $languageId,
        ?string $examId = null,
        ?string $canonicalTopicId = null,
        ?string $difficulty = null,
        bool $countPending = true,
    ): GenerationPlan {
        $held = $this->counts($languageId, $examId, $canonicalTopicId, $difficulty);

        $existing = $held['approved'] + ($countPending ? $held['pending'] : 0);

        return new GenerationPlan(
            requested: $requested,
            approved: $held['approved'],
            pending: $held['pending'],
            rejected: $held['rejected'],
            deficit: max(0, $requested - $existing),
            canonicalTopicId: $canonicalTopicId,
            difficulty: $difficulty,
        );
    }

    /**
     * Plans every configured target for an exam, busiest shortfall first.
     *
     * This is what makes a content backlog visible: rather than an operator
     * guessing which topic is thin, the targets say so.
     *
     * @return Collection<int, GenerationPlan>
     */
    public function planExam(string $examId, bool $countPending = true): Collection
    {
        return QuestionGenerationTarget::query()
            ->where('exam_id', $examId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get()
            ->map(fn (QuestionGenerationTarget $target): GenerationPlan => $this->planRequest(
                requested: $target->target_count,
                languageId: $target->language_id,
                examId: $target->exam_id,
                canonicalTopicId: $target->canonical_topic_id,
                difficulty: $target->difficulty,
                countPending: $countPending,
            ))
            ->sortByDesc(fn (GenerationPlan $plan): int => $plan->deficit)
            ->values();
    }

    /**
     * @return array{approved: int, pending: int, rejected: int}
     */
    private function counts(
        string $languageId,
        ?string $examId,
        ?string $canonicalTopicId,
        ?string $difficulty,
    ): array {
        $byStatus = Question::query()
            // Only questions that can actually be printed in this language.
            // A Tamil target is not satisfied by English questions, however
            // many of them the topic happens to hold.
            ->availableIn($languageId)
            ->when($examId, fn ($q) => $q->whereHas(
                'examMappings',
                fn ($m) => $m->where('exam_id', $examId)->where('is_active', true),
            ))
            ->when($canonicalTopicId, fn ($q) => $q->where('canonical_topic_id', $canonicalTopicId))
            ->when($difficulty, fn ($q) => $q->where('difficulty', $difficulty))
            ->selectRaw('status, count(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'approved' => (int) $byStatus->get('APPROVED', 0),
            'pending' => (int) $byStatus->only(self::IN_FLIGHT)->sum(),
            'rejected' => (int) $byStatus->get('REJECTED', 0),
        ];
    }
}
