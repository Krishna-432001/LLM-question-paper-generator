<?php

declare(strict_types=1);

namespace App\Domain\Tests;

use App\Models\Language;
use App\Models\Question;
use App\Models\Test;

/**
 * A question's wording in each of its paper's languages.
 *
 * Shared by the runner, the resume call and the solutions so the three cannot
 * drift: a bilingual paper that showed both languages while it was being
 * taken and one language in its solutions would leave the student unable to
 * match the solution to what they read.
 */
final class PaperText
{
    /**
     * Keyed by language code, in print order (primary first). Each entry is
     * the validated translation in that language, or the master when there is
     * none — Question::textIn's rule. Selection already guarantees a real
     * translation for papers built since language modes existed; the fallback
     * keeps older papers readable.
     *
     * @param  iterable<Language>  $languages
     * @return array<string, array{question_text: ?string, options: array<string, string>, explanation?: ?string}>
     */
    public static function byLanguage(Question $question, iterable $languages, bool $withExplanation = false): array
    {
        $texts = [];

        foreach ($languages as $language) {
            $text = $question->textIn($language->getKey());

            $entry = [
                'question_text' => $text?->question_text,
                'options' => $text?->optionMap() ?? [],
            ];

            if ($withExplanation) {
                $entry['explanation'] = $text?->explanation;
            }

            $texts[(string) $language->code] = $entry;
        }

        return $texts;
    }

    /**
     * The paper's languages as the client needs them: the code that keys
     * `texts`, and a name to label the toggle with.
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
