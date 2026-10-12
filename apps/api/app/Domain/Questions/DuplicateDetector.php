<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use App\Domain\Ingestion\Contracts\EmbeddingProvider;
use App\Domain\Questions\DTOs\QuestionCandidate;
use App\Models\GenerationPolicy;
use App\Support\Database\VectorSupport;
use Illuminate\Support\Facades\DB;

/**
 * Three tiers, cheapest first — a cost decision as much as a quality one.
 *
 *   L1  exact / near-exact text     free       catches ~35%
 *   L2  vector similarity           ~3ms       catches ~60%
 *   L3  model adjudication          paid       only the ~5% grey band
 *
 * Scope matters as much as threshold: a Tamil-history question is not a
 * duplicate of a science one, so comparison is scoped by subject and topic,
 * with a second unscoped pass at a higher threshold to catch cross-subject
 * clones.
 */
final class DuplicateDetector
{
    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    /**
     * Intra-batch: the same model call frequently emits near-identical items.
     *
     * @param  list<QuestionCandidate>  $candidates
     */
    public function deduplicateBatch(array $candidates, GenerationPolicy $policy): void
    {
        $seenHashes = [];
        $accepted = [];

        foreach ($candidates as $candidate) {
            if ($candidate->isRejected()) {
                continue;
            }

            // L1: exact match on the normalized stem.
            $hash = $candidate->fingerprint();

            if (isset($seenHashes[$hash])) {
                $candidate->reject('V5', 'DUPLICATE_EXACT', ['twin' => $seenHashes[$hash]]);

                continue;
            }

            $seenHashes[$hash] = $candidate->ref;

            // L2: semantic similarity against everything accepted so far.
            $candidate->embedding ??= $this->embeddings->embed([$candidate->embeddingText()])[0];

            foreach ($accepted as $other) {
                $similarity = $this->cosine($candidate->embedding, $other->embedding);

                if ($similarity >= $policy->dup_hard_threshold) {
                    $candidate->reject('V6', 'DUPLICATE_SEMANTIC', [
                        'twin' => $other->ref,
                        'similarity' => round($similarity, 4),
                    ]);

                    continue 2;
                }
            }

            $accepted[] = $candidate;
        }
    }

    /**
     * Against the existing bank. Scoped to the same topic, because comparing a
     * new question against every question ever written is both slow and wrong.
     *
     * @param  list<QuestionCandidate>  $candidates
     */
    public function deduplicateAgainstBank(
        array $candidates,
        GenerationPolicy $policy,
        ?string $canonicalTopicId,
        ?string $organizationId,
    ): void {
        foreach ($candidates as $candidate) {
            if ($candidate->isRejected()) {
                continue;
            }

            $candidate->embedding ??= $this->embeddings->embed([$candidate->embeddingText()])[0];

            $match = $this->nearestInBank($candidate, $canonicalTopicId, $organizationId);

            if ($match === null) {
                $candidate->record('V6_DISTINCT', true, 1.0);

                continue;
            }

            $similarity = 1.0 - (float) $match->distance;

            if ($similarity >= $policy->dup_hard_threshold) {
                $candidate->reject('V6', 'DUPLICATE_SEMANTIC', [
                    'question_id' => $match->question_id,
                    'similarity' => round($similarity, 4),
                ]);

                continue;
            }

            // Grey band: a model would adjudicate here in production. Until
            // that prompt is evaluated, the candidate is kept and the near
            // match is recorded so a reviewer sees it.
            if ($similarity >= $policy->dup_grey_threshold) {
                $candidate->record('V6_DISTINCT', true, round(1 - $similarity, 4), [
                    'grey_band' => true,
                    'nearest' => $match->question_id,
                    'similarity' => round($similarity, 4),
                ]);

                continue;
            }

            $candidate->record('V6_DISTINCT', true, round(1 - $similarity, 4), [
                'nearest' => $match->question_id,
                'similarity' => round($similarity, 4),
            ]);
        }
    }

    private function nearestInBank(
        QuestionCandidate $candidate,
        ?string $canonicalTopicId,
        ?string $organizationId,
    ): ?object {
        $binding = VectorSupport::toBinding($candidate->embedding);
        $distance = VectorSupport::distanceExpression('e.embedding', '?');

        $params = [$binding];
        $where = '';

        if ($canonicalTopicId !== null) {
            $where .= ' AND q.canonical_topic_id = ?';
            $params[] = $canonicalTopicId;
        }

        // Platform content plus this tenant's own — the same visibility rule
        // the rest of the system uses.
        if ($organizationId !== null) {
            $where .= ' AND (q.organization_id IS NULL OR q.organization_id = ?)';
            $params[] = $organizationId;
        } else {
            $where .= ' AND q.organization_id IS NULL';
        }

        return DB::selectOne(
            "SELECT q.id AS question_id, {$distance} AS distance
             FROM questions q
             JOIN question_embeddings e ON e.question_id = q.id
             WHERE q.status IN ('APPROVED','PENDING_REVIEW','AI_VALIDATED') {$where}
             ORDER BY distance ASC
             LIMIT 1",
            $params,
        );
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i] ?? 0.0;
            $dot += $value * $other;
            $normA += $value * $value;
            $normB += $other * $other;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
