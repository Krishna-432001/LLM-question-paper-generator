<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use App\Domain\Questions\DTOs\QuestionCandidate;

/**
 * Turns the gauntlet's per-check results into one 0–100 score.
 *
 * The weights encode what actually matters: grounding and answer agreement
 * together are 42 points, because a question that is unsupported or wrongly
 * keyed is worthless regardless of how elegant its distractors are.
 */
final class QualityScorer
{
    private const WEIGHTS = [
        'V7_GROUNDING' => 22,      // is it actually supported by the source
        'V8_ANSWER' => 20,         // does an independent solve agree
        'V9_OPTIONS' => 15,        // are the distractors doing work
        'V6_DISTINCT' => 12,       // is it different from what we already have
        'V4_QUOTE' => 10,          // verbatim evidence
        'V10_DIFFICULTY' => 8,     // difficulty confidence
        'V2_STRUCTURE' => 8,       // well-formed
        'V2_LENGTH_BIAS' => 5,     // no length tell
    ];

    public function score(QuestionCandidate $candidate): float
    {
        $total = 0.0;

        foreach (self::WEIGHTS as $code => $weight) {
            $total += $weight * $this->componentScore($candidate, $code);
        }

        // The model's own confidence is a weak signal, so it can only nudge.
        $total *= 0.95 + (0.05 * max(0.0, min(1.0, $candidate->confidence)));

        return round(max(0.0, min(100.0, $total)), 2);
    }

    /**
     * Decides where a scored candidate goes.
     *
     * Auto-approval is off by default and is earned per subject: only after a
     * reviewer override rate below 5% over 500 questions does it get enabled.
     * Automation is measured agreement, not an assumption.
     */
    public function routeFor(float $score, float $autoApprove, float $reviewFloor, bool $allowAuto): string
    {
        return match (true) {
            $score < $reviewFloor => 'REJECTED',
            $score >= $autoApprove && $allowAuto => 'APPROVED',
            default => 'PENDING_REVIEW',
        };
    }

    private function componentScore(QuestionCandidate $candidate, string $code): float
    {
        $check = $candidate->checks[$code] ?? null;

        if ($check === null) {
            // A check that never ran (validator outage) scores neutral rather
            // than zero, so an outage does not mass-reject good questions.
            return 0.5;
        }

        if ($check['passed'] === false) {
            return 0.0;
        }

        return max(0.0, min(1.0, $check['score'] ?? 1.0));
    }
}
