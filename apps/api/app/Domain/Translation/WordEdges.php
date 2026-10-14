<?php

declare(strict_types=1);

namespace App\Domain\Translation;

/**
 * Where a glossary word may end inside running text.
 *
 * English is easy: a word ends at a non-letter. Tamil is not. A noun takes
 * its case ending directly (சென்னை → சென்னையில்), so "end of word" alone
 * would miss most real occurrences — but "anything may follow" is far worse:
 * சேர matches inside சேர்மம் (compound), வரி inside வரிசைப்படுத்துக
 * (arrange), மண் inside மண்டபம் (hall). The rule here is the narrow one: a
 * Tamil term ends at a word boundary, or at one of a short list of case and
 * plural endings that is itself followed by a boundary. Never directly
 * before a vowel sign or the pulli, which would mean we cut a letter in half.
 */
final class WordEdges
{
    /** Case, plural and sandhi endings that may follow a Tamil noun. */
    public const TAMIL_ENDINGS = [
        'களுக்கு', 'களிடம்', 'களின்', 'களில்', 'களால்', 'களும்', 'களை', 'கள்',
        'யுடன்', 'யிடம்', 'யில்', 'யின்', 'யால்', 'யும்', 'யை', 'யே',
        'வுக்கு', 'வுடன்', 'விடம்', 'வில்', 'வின்', 'வால்', 'வும்', 'வை', 'வே',
        'க்கு', 'உடன்', 'இல்', 'இன்', 'ஆல்',
        'க்', 'ச்', 'த்', 'ப்',
    ];

    /** Fewest letters a Tamil term may have before it is matched on its own. */
    public const MIN_TAMIL_LETTERS = 3;

    private const BOUNDARY = '(?![\p{L}\p{M}\p{N}])';

    public static function isLatin(string $text): bool
    {
        return preg_match('/^\P{L}*\p{Latin}/u', $text) === 1;
    }

    public static function isTamil(string $text): bool
    {
        return preg_match('/[\x{0B80}-\x{0BFF}]/u', $text) === 1;
    }

    /** A strict end of word, for any script. */
    public static function boundary(): string
    {
        return self::BOUNDARY;
    }

    /** End of a Tamil noun: a boundary, or a known ending then a boundary. */
    public static function tamilEnd(): string
    {
        $endings = implode('|', array_map(static fn (string $e): string => preg_quote($e, '/'), self::TAMIL_ENDINGS));

        return '(?=(?:'.$endings.')?'.self::BOUNDARY.')';
    }

    /** The right edge to use after a term written like $sample. */
    public static function after(string $sample): string
    {
        return self::isTamil($sample) ? self::tamilEnd() : self::BOUNDARY;
    }

    /** Letters only, not the vowel signs and pulli that ride on them. */
    public static function letterCount(string $text): int
    {
        return (int) preg_match_all('/\p{L}/u', $text);
    }
}
