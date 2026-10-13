<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use Mpdf\Language\LanguageToFont;

/**
 * Sends Tamil to a font family that has only an upright face.
 *
 * mPDF sets Tamil in FreeSerif, and FreeSerif carries Tamil glyphs in its
 * regular face alone — FreeSerifBold and FreeSerifItalic have none. Any Tamil
 * inside <b>, <h1> or an italic block (a test titled in Tamil, the Tamil
 * "Instructions" heading, a quote from a Tamil textbook) was therefore set in
 * a face without the glyphs and came out blank.
 *
 * Mapped to a family whose only face is the regular one, mPDF finds no bold
 * or italic variant and falls back to upright. Upright Tamil where bold was
 * asked for is a small typographic loss; missing Tamil is a broken paper.
 */
final class UprightTamilFont extends LanguageToFont
{
    /** mPDF font family names are lower case. */
    public const FAMILY = 'tamilserif';

    /** @return array<string, array<string, mixed>> fontdata entry for the family */
    public static function fontData(): array
    {
        return [
            self::FAMILY => [
                'R' => 'FreeSerif.ttf',
                // Same shaping as mPDF's own FreeSerif entry; without OTL the
                // vowel signs detach from their consonants.
                'useOTL' => 0xFF,
            ],
        ];
    }

    /**
     * @param  string  $llcc
     * @param  bool  $adobeCJK
     * @return array{0: bool, 1: string}|mixed
     */
    public function getLanguageOptions($llcc, $adobeCJK)
    {
        $lang = strtolower(explode('-', (string) $llcc)[0]);

        if ($lang === 'ta' || $lang === 'tam') {
            return [false, self::FAMILY];
        }

        return parent::getLanguageOptions($llcc, $adobeCJK);
    }
}
