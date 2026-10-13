<?php

declare(strict_types=1);

namespace App\Domain\Tests;

use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionTranslation;
use App\Models\Test;
use App\Models\TestQuestion;
use Illuminate\Support\Collection;

/**
 * Which translation a paper prints, in each of its languages.
 *
 * The one rule shared by the runner, a resumed attempt, the solutions, every
 * exported document and the document's content hash. Before it existed each
 * of them looked the text up again on its own, and they disagreed: a Tamil
 * translation that had gone STALE made the PDF fall back to the English
 * master while the runner, read later, showed the re-approved Tamil — and the
 * stale English PDF kept being served because nothing in its hash had moved.
 *
 * Three decisions hold it together:
 *
 *   - A paper is printed from the rows it was assembled from. Assembly pins
 *     one row per language on test_questions, and everything reads the pin.
 *     Re-translation adds new version rows rather than editing old ones, so a
 *     fresh lookup at print time could pick a row the student never saw.
 *   - A pinned row keeps printing while it is something a reviewer passed:
 *     validated, approved, or STALE — its source has moved on since, which
 *     does not make the text it was assembled with wrong. A row later
 *     REJECTED is no longer printed.
 *   - Text is never filed under a language it is not in. When no row can be
 *     printed in a language, that language is marked unavailable. Falling back
 *     to the master put English on a Tamil paper labelled as Tamil.
 */
final class PaperText
{
    /** Statuses a translation must hold to be put on a new paper. */
    public const USABLE = ['AI_VALIDATED', 'HUMAN_APPROVED'];

    /** Statuses a pinned translation may hold and still be printed. */
    private const PRINTABLE_WHEN_PINNED = ['AI_VALIDATED', 'HUMAN_APPROVED', 'STALE'];

    /**
     * What a test must have loaded for every read below to stay in memory.
     *
     * @var list<string>
     */
    public const RELATIONS = [
        'questions.translation',
        'questions.secondaryTranslation',
        'questions.question.translations',
    ];

    /**
     * The row to assemble a question from in one language: its master when
     * the master is in that language, otherwise the newest validated
     * translation. Never a row in another language.
     *
     * Deliberately not Question::translationIn/textIn: those answer "what
     * should a reader see now", which changes as translations are revised,
     * and textIn falls back to the master in whatever language it is.
     */
    public static function usableTranslation(Question $question, string $languageId): ?QuestionTranslation
    {
        $languageId = strtolower($languageId);

        return $question->translations
            ->filter(static fn (QuestionTranslation $t): bool => strtolower((string) $t->language_id) === $languageId
                && ($t->is_master || in_array($t->translation_status, self::USABLE, true)))
            ->sortBy([
                static fn (QuestionTranslation $a, QuestionTranslation $b): int => (int) $b->is_master <=> (int) $a->is_master,
                static fn (QuestionTranslation $a, QuestionTranslation $b): int => (int) $b->translation_version <=> (int) $a->translation_version,
            ])
            ->first();
    }

    /**
     * The row one test question prints in, for the paper language in $slot
     * (0 = primary, 1 = second language of a bilingual paper).
     *
     * The pin when there is one and it is still printable; a live lookup for
     * papers assembled before pins existed, or whose pinned row was deleted
     * or rejected; null when the language has nothing printable at all.
     */
    public static function resolve(TestQuestion $testQuestion, Language $language, int $slot): ?QuestionTranslation
    {
        $pinned = match ($slot) {
            0 => $testQuestion->translation,
            1 => $testQuestion->secondaryTranslation,
            default => null,
        };

        if (
            $pinned !== null
            && strtolower((string) $pinned->language_id) === strtolower((string) $language->getKey())
            && ($pinned->is_master || in_array($pinned->translation_status, self::PRINTABLE_WHEN_PINNED, true))
        ) {
            return $pinned;
        }

        return self::usableTranslation($testQuestion->question, (string) $language->getKey());
    }

    /**
     * One question's text in each paper language, keyed by language code in
     * print order. `available` is false when a language has nothing
     * printable; its text is then null rather than another language's.
     *
     * @param  Collection<int, Language>  $languages  print order, from Test::paperLanguages()
     * @return array<string, array{question_text: ?string, options: array<string, string>, available: bool, explanation?: ?string}>
     */
    public static function forQuestion(TestQuestion $testQuestion, Collection $languages, bool $withExplanation = false): array
    {
        $texts = [];

        foreach ($languages->values() as $slot => $language) {
            $translation = self::resolve($testQuestion, $language, $slot);

            $entry = [
                'question_text' => $translation?->question_text,
                'options' => $translation?->optionMap() ?? [],
                'available' => $translation !== null,
            ];

            if ($withExplanation) {
                $entry['explanation'] = $translation?->explanation;
            }

            $texts[(string) $language->code] = $entry;
        }

        return $texts;
    }

    /**
     * The text fields of one question in an API payload: `texts` for every
     * language, and the primary language again in the fields clients used
     * before language modes. Those fields are null when the primary has
     * nothing printable — never another language's text in its place.
     *
     * @param  Collection<int, Language>  $languages
     * @return array<string, mixed>
     */
    public static function payloadFor(TestQuestion $testQuestion, Collection $languages, bool $withExplanation = false): array
    {
        $texts = self::forQuestion($testQuestion, $languages, $withExplanation);
        $primary = reset($texts) ?: ['question_text' => null, 'options' => [], 'available' => false, 'explanation' => null];

        return [
            'question_text' => $primary['question_text'],
            'options' => $primary['available'] ? $primary['options'] : null,
        ] + ($withExplanation ? ['explanation' => $primary['explanation'] ?? null] : []) + [
            'texts' => $texts,
        ];
    }

    /**
     * Everything about the rows a question prints from that can change the
     * printed bytes: which row, its version and status, and a digest of its
     * text — the digest because a direct database edit need not touch
     * updated_at, and a cached paper must not outlive its text.
     *
     * @param  Collection<int, Language>  $languages
     */
    public static function fingerprint(TestQuestion $testQuestion, Collection $languages): string
    {
        $parts = [];

        foreach ($languages->values() as $slot => $language) {
            $t = self::resolve($testQuestion, $language, $slot);

            $parts[] = $t === null
                ? $language->code.'=none'
                : $language->code.'='.$t->getKey().'@'.$t->translation_version.'/'.$t->translation_status
                    .'/'.sha1(implode("\x1f", [(string) $t->question_text, ...array_values($t->optionMap()), (string) $t->explanation]));
        }

        return implode(';', $parts);
    }

    /**
     * The paper's languages as the client needs them: the code that keys
     * `texts`, and a name to label it with.
     *
     * @return list<array{id: string, code: string, name: string, native_name: ?string}>
     */
    public static function languagesOf(Test $test): array
    {
        return $test->paperLanguages()
            ->map(static fn (Language $l): array => [
                'id' => (string) $l->getKey(),
                'code' => (string) $l->code,
                'name' => (string) $l->name,
                'native_name' => $l->native_name,
            ])
            ->values()
            ->all();
    }
}
