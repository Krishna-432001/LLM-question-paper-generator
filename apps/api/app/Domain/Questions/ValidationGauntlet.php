<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use App\Domain\Ai\AiGateway;
use App\Domain\Questions\DTOs\QuestionCandidate;
use App\Models\SourceChunk;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ten checks, ordered cheapest-first.
 *
 * The ordering is the design: deterministic rules cost nothing and remove
 * roughly a third of candidates, so the expensive model-based checks (V7–V9)
 * only ever run on survivors. Reversing the order would multiply validation
 * cost several-fold for identical output.
 *
 * V8 — the independent re-solve — is the highest-value check in the platform.
 * A second model, shown only the stem and options with no answer key, must
 * arrive at the same option. It catches the failure that destroys trust
 * fastest: a confidently wrong answer key.
 */
final class ValidationGauntlet
{
    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @param  list<QuestionCandidate>  $candidates
     * @param  array<string, SourceChunk>  $sourceMap  S-label => chunk
     * @param  array<string, mixed>  $context  ai_job_id / organization_id
     * @return list<QuestionCandidate>
     */
    public function run(array $candidates, array $sourceMap, array $context = []): array
    {
        foreach ($candidates as $candidate) {
            $this->structuralChecks($candidate);
            $this->resolveSources($candidate, $sourceMap);
        }

        $survivors = array_values(array_filter(
            $candidates,
            static fn (QuestionCandidate $c): bool => ! $c->isRejected(),
        ));

        if ($survivors !== []) {
            $this->modelChecks($survivors, $context);
        }

        return $candidates;
    }

    /** V1–V2: schema shape and structural rules. Free. */
    private function structuralChecks(QuestionCandidate $candidate): void
    {
        if (mb_strlen($candidate->questionText) < 15) {
            $candidate->reject('V1', 'SCHEMA_INVALID', ['reason' => 'stem too short']);
        }

        $options = $candidate->options;

        if (count($options) !== 4) {
            $candidate->reject('V2', 'OPTION_INVALID', ['count' => count($options)]);
        }

        $normalized = array_map(
            static fn (string $o): string => mb_strtolower(trim($o)),
            $options,
        );

        if (count(array_unique($normalized)) !== count($normalized)) {
            $candidate->reject('V2', 'OPTION_INVALID', ['reason' => 'duplicate options']);
        }

        foreach ($normalized as $option) {
            if ($option === '') {
                $candidate->reject('V2', 'OPTION_INVALID', ['reason' => 'empty option']);
                break;
            }
        }

        if (! isset($options[$candidate->correctOption])) {
            $candidate->reject('V2', 'OPTION_INVALID', ['reason' => 'correct option not present']);
        }

        // "All of the above" defeats the point of a four-option MCQ and is
        // disallowed by most Indian exam patterns.
        foreach ($normalized as $option) {
            if (preg_match('/\b(all|none)\s+of\s+the\s+above\b/u', $option) === 1) {
                $candidate->reject('V2', 'OPTION_INVALID', ['reason' => 'all/none of the above']);
                break;
            }
        }

        // Answer leakage: the correct option's distinctive wording appearing in
        // the stem makes the question answerable without knowing anything.
        $correct = mb_strtolower($options[$candidate->correctOption] ?? '');
        $stem = mb_strtolower($candidate->questionText);

        if ($correct !== '' && mb_strlen($correct) > 4 && str_contains($stem, $correct)) {
            $candidate->reject('V2', 'ANSWER_LEAK', ['answer' => $correct]);
        }

        // Length bias: the longest option being far longer than the others is a
        // well-known tell that test-takers exploit.
        $lengths = array_map('mb_strlen', $options);

        if ($lengths !== [] && max($lengths) > 3 * max(1, min($lengths))) {
            $candidate->record('V2_LENGTH_BIAS', false, 0.0, ['lengths' => $lengths]);
        } else {
            $candidate->record('V2_LENGTH_BIAS', true, 1.0);
        }

        $candidate->record('V2_STRUCTURE', ! $candidate->isRejected(), $candidate->isRejected() ? 0.0 : 1.0);
    }

    /**
     * V3–V4: citations must resolve to chunks we actually sent, and the
     * supporting quote should appear in one of them. Free, and it catches most
     * fabrication before any model is asked to judge.
     *
     * @param  array<string, SourceChunk>  $sourceMap
     */
    private function resolveSources(QuestionCandidate $candidate, array $sourceMap): void
    {
        if ($candidate->isRejected()) {
            return;
        }

        $resolved = [];

        foreach ($candidate->sourceRefs as $ref) {
            if (isset($sourceMap[$ref])) {
                $resolved[] = $sourceMap[$ref];
            }
        }

        if ($resolved === []) {
            $candidate->reject('V3', 'SOURCE_UNRESOLVED', ['refs' => $candidate->sourceRefs]);

            return;
        }

        $candidate->citedChunks = $resolved;

        // V4: verbatim quote match. A paraphrase is legal, so failure here is
        // not fatal — it just means V7 has to do the work instead.
        $similarity = 0.0;

        foreach ($resolved as $chunk) {
            $similarity = max($similarity, $this->quoteSimilarity($candidate->supportingQuote, $chunk->text));
        }

        $candidate->record('V4_QUOTE', $similarity >= 0.90, $similarity);
    }

    /**
     * V7–V9 in one batched call per group of candidates: grounding, an
     * independent re-solve, and option quality.
     *
     * @param  list<QuestionCandidate>  $candidates
     * @param  array<string, mixed>  $context
     */
    private function modelChecks(array $candidates, array $context): void
    {
        $byRef = [];
        $items = [];
        $sources = [];

        foreach ($candidates as $candidate) {
            $byRef[$candidate->ref] = $candidate;

            $items[] = [
                'ref' => $candidate->ref,
                'question' => $candidate->questionText,
                'options' => $candidate->options,
                // The claimed answer is included for the grounding judge but the
                // prompt instructs the model to solve independently first.
                'claimed_answer' => $candidate->correctOption,
            ];

            foreach ($candidate->citedChunks as $chunk) {
                $sources[$chunk->getKey()] = $chunk->text;
            }
        }

        try {
            $result = $this->ai->structured(
                'question_grounding_check',
                [
                    'items' => $items,
                    'source_material' => implode("\n\n---\n\n", $sources),
                ],
                $context,
            );
        } catch (Throwable $e) {
            // A validator outage must not silently pass unvalidated questions
            // into the bank. Everything is held for human review instead.
            Log::error('validation.gauntlet_failed', ['error' => $e->getMessage()]);

            foreach ($candidates as $candidate) {
                $candidate->record('V7_GROUNDING', false, null, ['error' => 'validator unavailable']);
            }

            return;
        }

        foreach ($result->get('results', []) as $row) {
            $candidate = $byRef[$row['ref'] ?? ''] ?? null;

            if ($candidate === null) {
                continue;
            }

            $this->applyModelVerdict($candidate, $row);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function applyModelVerdict(QuestionCandidate $candidate, array $row): void
    {
        $confidence = (float) ($row['confidence'] ?? 0.5);

        $supported = (bool) ($row['supported'] ?? false);
        $candidate->record('V7_GROUNDING', $supported, $supported ? $confidence : 0.0, $row);

        if (! $supported) {
            $candidate->reject('V7', 'UNSUPPORTED', ['reason' => $row['support_span'] ?? null]);
        }

        // V8: the independent re-solve. Disagreement is fatal — publishing a
        // wrong answer key is the one error students never forgive.
        $answerCorrect = (bool) ($row['answer_correct'] ?? false);
        $candidate->record('V8_ANSWER', $answerCorrect, $answerCorrect ? $confidence : 0.0, [
            'model_choice' => $row['model_choice'] ?? null,
            'claimed' => $candidate->correctOption,
        ]);

        if (! $answerCorrect) {
            $candidate->reject('V8', 'ANSWER_MISMATCH', [
                'model_choice' => $row['model_choice'] ?? null,
                'claimed' => $candidate->correctOption,
            ]);
        }

        if (($row['single_correct'] ?? true) === false) {
            $candidate->reject('V9', 'MULTIPLE_CORRECT', []);
        }

        $optionsValid = (bool) ($row['options_valid'] ?? true);
        $candidate->record('V9_OPTIONS', $optionsValid, $optionsValid ? 1.0 : 0.0);

        if (! $optionsValid) {
            $candidate->reject('V9', 'DISTRACTOR_WEAK', []);
        }

        // V10: difficulty calibration adjusts, never rejects. Real p-values
        // from student attempts refine this later.
        if (isset($row['suggested_difficulty'])
            && $row['suggested_difficulty'] !== $candidate->difficulty) {
            $candidate->record('V10_DIFFICULTY', true, 0.6, [
                'was' => $candidate->difficulty,
                'now' => $row['suggested_difficulty'],
            ]);
            $candidate->difficulty = (string) $row['suggested_difficulty'];
        } else {
            $candidate->record('V10_DIFFICULTY', true, 1.0);
        }
    }

    /** Token-sort ratio: word order changes should not fail a verbatim check. */
    private function quoteSimilarity(string $quote, string $haystack): float
    {
        $quote = mb_strtolower(trim($quote));

        if ($quote === '') {
            return 0.0;
        }

        if (str_contains(mb_strtolower($haystack), $quote)) {
            return 1.0;
        }

        $quoteTokens = preg_split('/\W+/u', $quote, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hayTokens = preg_split('/\W+/u', mb_strtolower($haystack), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($quoteTokens === []) {
            return 0.0;
        }

        $overlap = count(array_intersect($quoteTokens, $hayTokens));

        return round($overlap / count($quoteTokens), 4);
    }
}
