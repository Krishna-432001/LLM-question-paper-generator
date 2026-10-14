<?php

declare(strict_types=1);

namespace App\Domain\Translation;

/**
 * The five checks a machine translation must pass before a student sees it.
 *
 *   V1 structure  — four options, every protected span back exactly once, and
 *                   the same digits as the source. A changed number is the
 *                   cheapest wrong answer a translation can introduce.
 *   V2 glossary   — the board's term was used, not a fluent synonym.
 *   V3 meaning    — an independent back-translation scored close enough.
 *   V4 answer     — a model that never saw the key, solving the translated
 *                   question, picked the keyed option. This is the check
 *                   that catches drift the similarity score misses.
 *   V5 language   — it is actually in the target script, of a sane length,
 *                   with no tokens left over.
 *
 * Every check runs even after one fails, so the reviewer sees the whole
 * picture at once rather than fixing one code and discovering the next.
 */
final class TranslationValidator
{
    public const MEANING_THRESHOLD = 0.85;

    public const MIN_LENGTH_RATIO = 0.4;

    public const MAX_LENGTH_RATIO = 3.0;

    /** Share of letters that must be in the target script, by script. */
    private const SCRIPT_THRESHOLD = ['Latin' => 0.9];

    private const DEFAULT_SCRIPT_THRESHOLD = 0.6;

    /** ISO 15924 code (languages.script) → PCRE script name. */
    private const SCRIPTS = [
        'Latn' => 'Latin', 'Taml' => 'Tamil', 'Telu' => 'Telugu', 'Mlym' => 'Malayalam',
        'Deva' => 'Devanagari', 'Knda' => 'Kannada', 'Beng' => 'Bengali', 'Gujr' => 'Gujarati',
        'Orya' => 'Oriya', 'Guru' => 'Gurmukhi', 'Arab' => 'Arabic',
    ];

    public function __construct(
        private readonly ProtectedSpanMasker $masker,
        private readonly Glossary $glossary,
    ) {}

    /**
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $source      master text
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $translated  unmasked model output
     * @param  array<string, string>  $maskedTranslated  model output still carrying tokens, keyed like owners
     * @param  array<string, string>  $owners  token → field it was issued for
     * @param  list<array{source: string, target: string}>  $terms  glossary terms present in the source
     * @param  array{back_translation?: string, meaning_score?: float|int, model_choice?: string, confidence?: float|int}|null  $verification
     * @return array{status: string, quality_score: float, report: array<string, mixed>}
     */
    public function validate(
        array $source,
        array $translated,
        array $maskedTranslated,
        array $owners,
        array $terms,
        ?array $verification,
        ?string $correctOption,
        string $targetScript,
    ): array {
        $notes = [];

        $v1 = $this->structure($source, $translated, $maskedTranslated, $owners);
        $v2 = $this->terminology($translated, $terms);
        $v3 = $this->meaning($verification);
        $v4 = $this->answer($verification, $correctOption);
        $v5 = $this->language($source, $translated, $maskedTranslated, $targetScript);

        if ($v4['skipped']) {
            $notes[] = 'Answer integrity was not checked: the question has no answer key.';
        }

        if ($verification === null) {
            $notes[] = 'The verification call did not return a result for this item.';
        }

        if ($v5['script_threshold'] === null) {
            $notes[] = "No script check is defined for '{$targetScript}'.";
        }

        $checks = ['V1' => $v1, 'V2' => $v2, 'V3' => $v3, 'V4' => $v4, 'V5' => $v5];
        $codes = [
            'V1' => 'V1_STRUCTURE', 'V2' => 'V2_GLOSSARY', 'V3' => 'V3_MEANING',
            'V4' => 'V4_ANSWER', 'V5' => 'V5_LANGUAGE',
        ];

        $failures = [];

        foreach ($checks as $id => $check) {
            if (! $check['passed']) {
                $failures[] = $codes[$id];
            }
        }

        $score = $this->score($checks, $verification);

        return [
            'status' => $failures === [] ? 'AI_VALIDATED' : 'NEEDS_REVIEW',
            'quality_score' => $score,
            'report' => [
                'failures' => $failures,
                'checks' => $checks,
                'back_translation' => $verification['back_translation'] ?? null,
                'model_confidence' => isset($verification['confidence']) ? (float) $verification['confidence'] : null,
                'notes' => $notes,
            ],
        ];
    }

    /**
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $source
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $translated
     * @param  array<string, string>  $masked
     * @param  array<string, string>  $owners
     * @return array<string, mixed>
     */
    private function structure(array $source, array $translated, array $masked, array $owners): array
    {
        $problems = [];

        if (trim($translated['question_text']) === '') {
            $problems[] = 'The question text is empty.';
        }

        $emptyOptions = [];

        foreach (['A', 'B', 'C', 'D'] as $key) {
            if (trim((string) ($translated['options'][$key] ?? '')) === '') {
                $emptyOptions[] = $key;
            }
        }

        if ($emptyOptions !== []) {
            $problems[] = 'Options missing or empty: '.implode(', ', $emptyOptions).'.';
        }

        // Each token must come back exactly once, and in the field it left
        // from: a number that moved from the stem into option C is still a
        // changed question.
        $lost = [];
        $repeated = [];
        $invented = [];

        foreach ($masked as $field => $text) {
            $expected = array_keys(array_filter($owners, static fn (string $owner): bool => $owner === $field));
            $check = $this->masker->placeholderProblems($text, $expected);

            $lost = [...$lost, ...$check['missing']];
            $repeated = [...$repeated, ...$check['duplicated']];
            $invented = [...$invented, ...$check['unexpected']];
        }

        if ($lost !== []) {
            $problems[] = 'Protected spans lost: '.implode(', ', $lost).'.';
        }

        if ($repeated !== []) {
            $problems[] = 'Protected spans repeated: '.implode(', ', $repeated).'.';
        }

        if ($invented !== []) {
            $problems[] = 'Placeholders that were never issued: '.implode(', ', $invented).'.';
        }

        $sourceDigits = $this->digits($source);
        $targetDigits = $this->digits($translated);

        if ($sourceDigits !== $targetDigits) {
            $problems[] = 'The numbers differ from the source.';
        }

        return [
            'passed' => $problems === [],
            'problems' => $problems,
            'source_numbers' => $sourceDigits,
            'translated_numbers' => $targetDigits,
        ];
    }

    /**
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $translated
     * @param  list<array{source: string, target: string}>  $terms
     * @return array<string, mixed>
     */
    private function terminology(array $translated, array $terms): array
    {
        $text = $this->joined($translated);
        $missing = [];

        foreach ($terms as $term) {
            if (! $this->glossary->termUsed($term['target'], $text)) {
                $missing[] = ['source' => $term['source'], 'expected' => $term['target']];
            }
        }

        return [
            'passed' => $missing === [],
            'checked' => count($terms),
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $verification
     * @return array<string, mixed>
     */
    private function meaning(?array $verification): array
    {
        $score = isset($verification['meaning_score']) ? (float) $verification['meaning_score'] : null;

        return [
            'passed' => $score !== null && $score >= self::MEANING_THRESHOLD,
            'meaning_score' => $score,
            'threshold' => self::MEANING_THRESHOLD,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $verification
     * @return array<string, mixed>
     */
    private function answer(?array $verification, ?string $correctOption): array
    {
        if ($correctOption === null) {
            return ['passed' => true, 'skipped' => true, 'expected' => null, 'model_choice' => $verification['model_choice'] ?? null];
        }

        $choice = isset($verification['model_choice']) ? strtoupper((string) $verification['model_choice']) : null;

        return [
            'passed' => $choice !== null && $choice === $correctOption,
            'skipped' => false,
            'expected' => $correctOption,
            'model_choice' => $choice,
        ];
    }

    /**
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $source
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $translated
     * @param  array<string, string>  $masked
     * @return array<string, mixed>
     */
    private function language(array $source, array $translated, array $masked, string $targetScript): array
    {
        $problems = [];
        $script = self::SCRIPTS[$targetScript] ?? null;
        $threshold = $script === null ? null : (self::SCRIPT_THRESHOLD[$script] ?? self::DEFAULT_SCRIPT_THRESHOLD);

        // Measured on the text with its tokens removed: a formula or a unit
        // that is legitimately Latin inside a Tamil question must not count
        // against it.
        $withoutTokens = (string) preg_replace(ProtectedSpanMasker::TOKEN, ' ', $this->masker->normalise(implode("\n", $masked)));
        $ratio = $script === null ? null : $this->scriptRatio($withoutTokens, $script);

        if ($ratio !== null && $threshold !== null && $ratio < $threshold) {
            $problems[] = sprintf('Only %d%% of the letters are in %s script (need %d%%).', (int) round($ratio * 100), $script, (int) round($threshold * 100));
        }

        $sourceLength = mb_strlen($this->joined($source, false));
        $lengthRatio = $sourceLength > 0 ? round(mb_strlen($this->joined($translated, false)) / $sourceLength, 3) : null;

        if ($lengthRatio !== null && ($lengthRatio < self::MIN_LENGTH_RATIO || $lengthRatio > self::MAX_LENGTH_RATIO)) {
            $problems[] = "The translation is {$lengthRatio}× the length of the source.";
        }

        $leftover = $this->masker->hasLeftoverTokens($this->joined($translated));

        if ($leftover) {
            $problems[] = 'Placeholder tokens were left in the final text.';
        }

        return [
            'passed' => $problems === [],
            'problems' => $problems,
            'script' => $script,
            'script_ratio' => $ratio === null ? null : round($ratio, 3),
            'script_threshold' => $threshold,
            'length_ratio' => $lengthRatio,
            'leftover_placeholders' => $leftover,
        ];
    }

    /**
     * Share of letters (with their combining marks) in the given script.
     * Text with no letters at all — an all-numeric aptitude item — passes.
     */
    private function scriptRatio(string $text, string $script): float
    {
        $letters = preg_match_all('/[\p{L}\p{M}]/u', $text);

        if ($letters === 0 || $letters === false) {
            return 1.0;
        }

        $inScript = preg_match_all('/\p{'.$script.'}/u', $text);

        return min(1.0, (int) $inScript / $letters);
    }

    /**
     * Every digit run in the item, sorted. A multiset comparison, so options
     * may be reworded around their numbers but a number may never change,
     * vanish or appear.
     *
     * @param  array{question_text: string, options: array<string, string>, explanation: string}  $fields
     * @return list<string>
     */
    private function digits(array $fields): array
    {
        // Subscript and superscript digits are numbers too: CO₂ written back
        // as CO has lost one.
        $text = strtr($this->joined($fields), [
            '₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4',
            '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9',
            '⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4',
            '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9',
        ]);

        preg_match_all('/\d+/u', $text, $m);

        $runs = array_map(static fn (string $d): string => ltrim($d, '0') === '' ? '0' : ltrim($d, '0'), $m[0]);
        sort($runs, SORT_STRING);

        return $runs;
    }

    /** @param  array{question_text: string, options: array<string, string>, explanation: string}  $fields */
    private function joined(array $fields, bool $withExplanation = true): string
    {
        $parts = [$fields['question_text'], ...array_values($fields['options'])];

        if ($withExplanation) {
            $parts[] = $fields['explanation'];
        }

        return implode("\n", $parts);
    }

    /**
     * 0–100. Weighted towards the two checks that decide whether the answer
     * is still right: structure and answer integrity.
     *
     * @param  array<string, array<string, mixed>>  $checks
     * @param  array<string, mixed>|null  $verification
     */
    private function score(array $checks, ?array $verification): float
    {
        $score = $checks['V1']['passed'] ? 25.0 : 0.0;

        $checked = (int) $checks['V2']['checked'];
        $score += $checked === 0 ? 15.0 : 15.0 * ($checked - count($checks['V2']['missing'])) / $checked;

        $score += 25.0 * max(0.0, min(1.0, (float) ($checks['V3']['meaning_score'] ?? 0.0)));

        if ($checks['V4']['skipped']) {
            $score += 25.0 * max(0.0, min(1.0, (float) ($verification['confidence'] ?? 0.5)));
        } elseif ($checks['V4']['passed']) {
            $score += 25.0;
        }

        $score += $checks['V5']['passed'] ? 10.0 : 0.0;

        return round(max(0.0, min(100.0, $score)), 2);
    }
}
