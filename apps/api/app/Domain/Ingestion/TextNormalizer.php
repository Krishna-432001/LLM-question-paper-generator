<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use Normalizer;

/**
 * Post-extraction cleanup.
 *
 * Everything here is deterministic and free. Doing it before chunking is what
 * stops a hyphenated line break or a running header from becoming part of a
 * question stem — errors that are cheap to prevent and expensive to detect
 * once they are embedded in the question bank.
 */
final class TextNormalizer
{
    /**
     * Lines repeated on this proportion of pages are running headers/footers
     * (book title, chapter name, page furniture) rather than content.
     */
    private const REPEAT_THRESHOLD = 0.55;

    /**
     * Structural headings are never furniture, even when they look repeated.
     *
     * Furniture keys mask digits so "Page 12" and "Page 13" collapse to one
     * key — but that also collapses "Unit 1" and "Unit 2", and a book whose
     * every page opens with a unit heading would have its entire chapter
     * structure deleted as a running header. Headings are content.
     */
    private const HEADING_GUARD = '/^\s*(unit|chapter|lesson|section|இயல்|பாடம்)\b/iu';

    /** ெ ே ை — the Tamil vowel signs drawn before their consonant. */
    private const PRE_BASE_SIGNS = '\x{0BC6}-\x{0BC8}';

    /** க … ஹ, the Tamil consonants (grantha letters included). */
    private const CONSONANTS = '\x{0B95}-\x{0BB9}';

    /**
     * @param  list<string>  $pages  raw page texts, in order
     * @return list<string>  normalized page texts, in order
     */
    public function normalizePages(array $pages): array
    {
        $furniture = $this->detectRunningFurniture($pages);

        return array_map(
            fn (string $page): string => $this->normalizePage($page, $furniture),
            $pages,
        );
    }

    /** @param  array<string, true>  $furniture */
    public function normalizePage(string $text, array $furniture = []): string
    {
        $text = $this->repairIndicExtraction($text);
        $text = $this->normalizeUnicode($text);
        $text = $this->stripImprint($text);
        $text = $this->stripFurniture($text, $furniture);
        $text = $this->dehyphenate($text);
        $text = $this->collapseWhitespace($text);

        return trim($text);
    }

    /**
     * Repairs the two ways a Tamil PDF text layer comes out damaged.
     *
     * 1. U+FFFD between a consonant and its vowel sign. Tamil vowel signs like
     *    ொ are drawn as two glyphs, and when the left half has no Unicode
     *    mapping the extractor emits a replacement character before the real
     *    sign: "க" + U+FFFD + "ொ". Deleting it restores "கொ" exactly — the
     *    codepoint carries no information, it is pure extraction damage.
     *    Left in, it reaches the printed paper, and it corrupted 89 of the
     *    90 pages of the first real textbook put through this pipeline.
     *
     * 2. Justified text set with per-character positioning, which the layout
     *    extractor renders as "இ ல க ் க ண". Runs of single Tamil characters
     *    separated by single spaces are rejoined; genuine word gaps are two
     *    or more columns wide and survive.
     *
     * 3. Visual order. ெ ே ை are drawn to the left of their consonant, and a
     *    PDF that stores glyphs in drawing order yields "ேதசிய" for "தேசிய"
     *    and "ெசன்ைன" for "சென்னை". See tamilInVisualOrder().
     */
    private function repairIndicExtraction(string $text): string
    {
        $text = (string) preg_replace('/\x{FFFD}/u', '', $text);

        // A vowel sign or pulli printed twice ("அமைச்்சரவை", "கொொண்்ட"):
        // some fonts draw one mark with two glyphs and each maps to the same
        // codepoint. Correct Tamil never repeats a dependent sign, so the
        // second is always extraction damage.
        $text = (string) preg_replace('/([\x{0BBE}-\x{0BCD}\x{0BD7}])\1+/u', '$1', $text);

        // Rejoin only where three or more single characters run together, so
        // ordinary short words are never welded onto their neighbours.
        $text = (string) preg_replace_callback(
            '/(?:[\x{0B80}-\x{0BFF}] ){2,}[\x{0B80}-\x{0BFF}]/u',
            static fn (array $m): string => str_replace(' ', '', $m[0]),
            $text,
        );

        if (! $this->tamilInVisualOrder($text)) {
            return $text;
        }

        // On a page in visual order every one of these signs belongs after
        // the consonant that follows it — the legal-looking "தைல" of "தலை"
        // included. NFC, which runs next, then fuses ெ/ே + ா into ொ/ோ.
        return (string) preg_replace(
            '/(['.self::PRE_BASE_SIGNS.'])(['.self::CONSONANTS.'](?:\x{0BCD}\x{0BB7})?)/u',
            '$2$1',
            $text,
        );
    }

    /**
     * Whether this text's Tamil was stored in drawing order, not Unicode order.
     *
     * Unicode only ever puts a vowel sign after a consonant, so ெ ே ை opening
     * a word, or following ் or another sign, cannot happen in correct text —
     * and is everywhere in visual-order text, where every word that starts
     * with "தே" or "செ" starts with the sign. A quarter of the signs stranded
     * like that is the threshold: correct text has none, and a stray one from
     * a damaged glyph must not flip the order of a whole page.
     */
    public function tamilInVisualOrder(string $text): bool
    {
        $signs = (int) preg_match_all('/['.self::PRE_BASE_SIGNS.']/u', $text);

        if ($signs === 0) {
            return false;
        }

        $stranded = (int) preg_match_all(
            '/(?<!['.self::CONSONANTS.'])['.self::PRE_BASE_SIGNS.'](?=['.self::CONSONANTS.'])/u',
            $text,
        );

        return $stranded > 0 && $stranded / $signs >= 0.25;
    }

    /**
     * Removes the publisher imprint that state-board PDFs stamp on every page.
     *
     * The running-furniture detector cannot catch these: a site watermark is
     * glued onto the end of a real content line rather than sitting on its own,
     * and the InDesign filename carries a page range that differs per file, so
     * it never repeats often enough to look like furniture.
     *
     * Left in place, the watermark lands inside chunks, gets embedded, and is
     * eventually quoted back as a supporting citation.
     */
    private function stripImprint(string $text): string
    {
        return (string) preg_replace(
            [
                // Bare URLs, e.g. the www.tntextbooks.in stamp.
                '/\s*\b(?:https?:\/\/)?www\.[\w.-]+\.[a-z]{2,}\b\s*/iu',
                // Print-production artifacts: "6th Std Tamil Term 1 Pages 1-24.indd 1"
                '/^.*\.indd\s+\d+\s*$/imu',
            ],
            ' ',
            $text,
        );
    }

    /**
     * NFC composition is what turns split Tamil combining marks back into the
     * single codepoints the rest of the system indexes and renders. Without
     * it, "இதயம்" can be stored as a sequence that neither matches a search
     * nor shapes correctly in a PDF.
     */
    public function normalizeUnicode(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        // Zero-width joiners survive OCR and break exact-match dedup while
        // being invisible to a reviewer. Strip them except where Indic
        // scripts need them (between a virama and a consonant).
        $text = preg_replace('/(?<!\x{0BCD})\x{200D}/u', '', $text) ?? $text;
        $text = str_replace("\u{200B}", '', $text);

        // Tesseract's Tamil model writes a zero-width non-joiner after every
        // pulli ("இந்தியாவின்‌"). Tamil forms no conjunct for it to prevent,
        // so it only ever stops a word matching its clean spelling.
        $text = preg_replace('/(?<=\x{0BCD})\x{200C}/u', '', $text) ?? $text;

        // Canonicalise the Tamil sri ligature, which OCR emits three ways.
        return str_replace(["\u{0BB6}\u{0BCD}\u{0BB0}\u{0BC0}"], "\u{0BB8}\u{0BCD}\u{0BB0}\u{0BC0}", $text);
    }

    /**
     * Joins words broken across a line by a hyphen. Applied before whitespace
     * collapsing so the line structure is still visible.
     */
    public function dehyphenate(string $text): string
    {
        return preg_replace('/(\p{L})[-\x{2010}]\n\s*(\p{Ll})/u', '$1$2', $text) ?? $text;
    }

    public function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/u', "\n", $text) ?? $text;

        // Three or more newlines carry no more meaning than two.
        return preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    }

    /**
     * @param  list<string>  $pages
     * @return array<string, true>
     */
    public function detectRunningFurniture(array $pages): array
    {
        if (count($pages) < 4) {
            return [];
        }

        $counts = [];

        foreach ($pages as $page) {
            $lines = preg_split('/\n/u', trim($page)) ?: [];
            // Only the first and last two lines of a page can be furniture.
            $candidates = array_merge(
                array_slice($lines, 0, 2),
                array_slice($lines, -2),
            );

            foreach (array_unique($candidates) as $line) {
                $key = $this->furnitureKey($line);

                if ($key !== '') {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        $threshold = count($pages) * self::REPEAT_THRESHOLD;
        $furniture = [];

        foreach ($counts as $key => $count) {
            if ($count >= $threshold) {
                $furniture[$key] = true;
            }
        }

        return $furniture;
    }

    /** @param  array<string, true>  $furniture */
    private function stripFurniture(string $text, array $furniture): string
    {
        $lines = preg_split('/\n/u', $text) ?: [];

        $kept = array_filter($lines, function (string $line) use ($furniture): bool {
            $trimmed = trim($line);

            // A line that is only a page number is always furniture.
            if (preg_match('/^[\p{Nd}\s\-–—|.]{1,12}$/u', $trimmed) === 1 && $trimmed !== '') {
                return false;
            }

            return ! isset($furniture[$this->furnitureKey($line)]);
        });

        return implode("\n", $kept);
    }

    /** Page numbers vary per page, so they are masked before comparison. */
    private function furnitureKey(string $line): string
    {
        if (preg_match(self::HEADING_GUARD, $line) === 1) {
            return '';   // never a furniture candidate
        }

        $key = mb_strtolower(trim($line));
        $key = preg_replace('/\p{Nd}+/u', '#', $key) ?? $key;
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;

        return mb_strlen($key) >= 3 ? $key : '';
    }
}
