<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers;

/**
 * Something the student should check about a paper they uploaded.
 *
 * Each warning is a stable code, its parameters, and an English sentence.
 * The student app translates by code (papers.warn.<code> in its i18n file,
 * with the same {placeholders}); the English sentence is for API clients and
 * for anything the app has no translation for yet.
 */
final class PaperWarning
{
    private const MESSAGES = [
        'pages_unreadable' => 'No readable text on page {pages}, so any questions there were not read.',
        'ocr_unsure' => 'OCR was unsure of page {pages}. Check those questions against the printed paper before trusting a score.',
        'skipped' => '{count} question(s) could not be read and were left out (numbers {numbers}). '
            .'A question is skipped rather than guessed when its four options cannot all be found.',
        'no_key' => 'No answer key was found in the PDF. You can sit the test now, but answers will not be '
            .'scored until you add the key (for example "1-A 2-C 3-B").',
        'partial_key' => 'The answer key covers {keyed} of {total} questions; the other {unkeyed} will not be '
            .'scored until you add their answers.',
        'not_in_language' => '{count} question(s) were not printed in {language} (or could not be read in it) '
            .'and will show in the language they were printed in.',
        'sections' => 'The paper has {count} parts that each number their questions from 1. They are kept in '
            .'order; a typed answer key should start again at 1 for each part.',
        'key_first_section_only' => 'The answer key has one set of numbers but the paper has {sections} parts, '
            .'so it was matched to the first part only. Add the other parts\' answers yourself.',
        'key_sections_mismatch' => 'The answer key has {key_sections} parts but the paper has {sections}; they '
            .'were matched in order, so check the answers.',
    ];

    private const LANGUAGE_NAMES = ['en' => 'English', 'ta' => 'Tamil'];

    /**
     * @param  array<string, int|string>  $params
     * @return array{code: string, params: array<string, int|string>, message: string}
     */
    public static function make(string $code, array $params = []): array
    {
        $message = self::MESSAGES[$code] ?? $code;

        foreach ($params as $name => $value) {
            $shown = $name === 'language' ? (self::LANGUAGE_NAMES[$value] ?? $value) : $value;
            $message = str_replace('{'.$name.'}', (string) $shown, $message);
        }

        return ['code' => $code, 'params' => $params, 'message' => $message];
    }
}
