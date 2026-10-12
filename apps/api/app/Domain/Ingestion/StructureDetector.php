<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\DTOs\ExtractedPage;

/**
 * Pass 1 of structure detection: free, deterministic heuristics.
 *
 * Sending 200 pages of text to a model to "find the chapters" is both wasteful
 * and unreliable. Instead we extract candidate headings here for ~₹0, and a
 * cheap model repairs the tree from the candidate list alone (a few thousand
 * tokens for a whole book) in pass 2.
 */
final class StructureDetector
{
    /**
     * Numbering patterns that open a chapter or lesson, in English and Tamil.
     * Tamil state-board books use இயல் (unit) and பாடம் (lesson).
     */
    /**
     * The trailing separator class must include the em-dash (U+2014) as well
     * as the hyphen and en-dash: Indian textbook headings are typically set as
     * "Unit 1 — The Living World", and leaving it unconsumed puts the dash at
     * the front of every chapter title and therefore into every citation.
     */
    /**
     * Tamil textbooks number their units in words, not digits: a Samacheer
     * reader opens with "இயல் ஒன்று", never "இயல் 1". A digit-only pattern
     * finds no chapters at all across the corpus this platform exists to read.
     */
    private const TAMIL_ORDINALS = [
        'ஒன்று' => '1', 'இரண்டு' => '2', 'மூன்று' => '3', 'நான்கு' => '4',
        'ஐந்து' => '5', 'ஆறு' => '6', 'ஏழு' => '7', 'எட்டு' => '8',
        'ஒன்பது' => '9', 'பத்து' => '10',
    ];

    private const HEADING_PATTERNS = [
        // The terminator is a lookahead, NOT \b. PCRE builds \b from \w, which
        // excludes combining marks even under /u — and every Tamil ordinal ends
        // in one ("ஒன்று" closes with U+0BC1). A \b here matches nothing in
        // Tamil at all, which silently disabled chapter detection across the
        // entire corpus this platform is built to read.
        'chapter' => '/^\s*(?:unit|chapter|இயல்)\s*[-–—:.]?\s*([\p{Nd}]{1,2}|[IVXLC]{1,5}|ஒன்று|இரண்டு|மூன்று|நான்கு|ஐந்து|ஆறு|ஏழு|எட்டு|ஒன்பது|பத்து)(?=[\s\-–—:.]|$)\s*[-–—:.]?\s*(.*)$/iu',
        'lesson' => '/^\s*(?:lesson|பாடம்)?\s*([\p{Nd}]{1,2}\.[\p{Nd}]{1,2})\s*[-–—:.]?\s*(.+)$/iu',
    ];

    /**
     * Section headings that mark book-back material rather than teaching prose.
     *
     * Getting this wrong is not cosmetic. An undetected exercise block is fed
     * to generation as source material, and the model then "generates" the
     * textbook's own questions back — complete with the printed options — so
     * the bank fills with copies of the exercises every student already has.
     *
     * The Tamil list is what a Samacheer reader actually prints: மதிப்பீடு
     * (evaluation) and கற்பவை கற்றபின் (after learning) head almost every
     * lesson, and neither appears in any English-derived word list.
     */
    public const EXERCISE_HEADINGS =
        'exercise|exercises|questions|book\s+back'
        .'|பயிற்சி|வினாக்கள்|மதிப்பீடு|கற்பவை\s+கற்றபின்'
        .'|குறுவினா|சிறுவினா|நெடுவினா|சிந்தனை\s+வினா|நயம்\s+அறிக';

    /**
     * Headings that resume teaching content after an exercise block.
     *
     * An exercise block has to end somewhere. Ending it only at the next
     * unit heading means one "மதிப்பீடு" swallows every remaining page of the
     * lesson — in a real reader that classified 50 of 65 chunks as exercises
     * and left almost nothing to generate from.
     */
    public const CONTENT_HEADINGS =
        'பாடலின்\s+பொருள்|நூல்\s+வெளி|சொல்லும்\s+பொருளும்'
        .'|கற்பவை|பாடல்|உரைநடை|செய்யுள்';

    private const EXERCISE_PATTERN = '/^\s*('.self::EXERCISE_HEADINGS.')\s*[.:]?\s*$/iu';

    /**
     * @param  list<ExtractedPage>  $pages
     * @return array{candidates: list<array<string, mixed>>, exercise_pages: list<int>}
     */
    /**
     * Print layout wraps a heading across two lines more often than not:
     *
     *     இயல்   www.tntextbooks.in
     *     ஒன்று      தமிழ்த்தேன்
     *
     * The marker, the number and the title then sit on different lines and no
     * single-line pattern can ever match. When a line is nothing but a marker,
     * the next non-empty line is folded onto it before matching.
     *
     * @param  list<string>  $lines
     */
    private function joinWrappedHeading(array $lines, int $index): string
    {
        $trimmed = trim($lines[$index] ?? '');

        if ($trimmed === '') {
            return '';
        }

        // Fold only a bare marker. A longer line is either a complete heading
        // already or ordinary prose, and folding it would invent headings.
        if (preg_match('/^(unit|chapter|இயல்|பாடம்)$/iu', $trimmed) !== 1) {
            return $trimmed;
        }

        for ($next = $index + 1; $next < count($lines) && $next <= $index + 2; $next++) {
            $candidate = trim($lines[$next] ?? '');

            if ($candidate !== '') {
                return $trimmed.' '.$candidate;
            }
        }

        return $trimmed;
    }

    /** Tamil unit numbers are words; everything downstream expects a number. */
    private function normaliseNumber(string $number): string
    {
        return self::TAMIL_ORDINALS[$number] ?? $number;
    }

    /**
     * @param  list<ExtractedPage>  $pages
     * @return array{candidates: list<array<string, mixed>>, exercise_pages: list<int>}
     */
    public function detect(array $pages): array
    {
        $candidates = [];
        $exercisePages = [];

        foreach ($pages as $page) {
            $lines = preg_split('/\n/u', $page->text) ?: [];

            foreach ($lines as $index => $line) {
                $trimmed = $this->joinWrappedHeading($lines, $index);

                if ($trimmed === '' || mb_strlen($trimmed) > 120) {
                    continue;   // headings are short
                }

                if (preg_match(self::EXERCISE_PATTERN, $trimmed) === 1) {
                    $exercisePages[] = $page->pageNumber;

                    continue;
                }

                foreach (self::HEADING_PATTERNS as $level => $pattern) {
                    if (preg_match($pattern, $trimmed, $m) !== 1) {
                        continue;
                    }

                    $title = trim($m[2] ?? '');

                    // A numbering match with no title on the same line is
                    // usually a cross-reference in body text, not a heading.
                    if ($title === '' || mb_strlen($title) < 3) {
                        continue;
                    }

                    $candidates[] = [
                        'level' => $level,
                        'number' => $this->normaliseNumber(trim($m[1])),
                        'title' => $title,
                        'page' => $page->pageNumber,
                        // Headings sit at the top of a page or after a blank
                        // line; deep-in-paragraph matches are weaker.
                        'confidence' => $index <= 2 ? 0.9 : 0.6,
                        'first_body_line' => $this->firstBodyLine($lines, $index),
                    ];

                    break;
                }
            }
        }

        return [
            'candidates' => $this->deduplicate($candidates),
            'exercise_pages' => array_values(array_unique($exercisePages)),
        ];
    }

    /**
     * Builds a chapter/lesson tree from candidates alone, with no model call.
     * Used directly when the heuristics are unambiguous, and as the input the
     * cheap-tier model repairs when they are not.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    public function buildTree(array $candidates, int $pageCount): array
    {
        $chapters = [];

        foreach ($candidates as $candidate) {
            if ($candidate['level'] === 'chapter') {
                $chapters[] = [
                    'number' => (string) $candidate['number'],
                    'title' => (string) $candidate['title'],
                    'start_page' => (int) $candidate['page'],
                    'end_page' => $pageCount,
                    'lessons' => [],
                ];

                continue;
            }

            if ($chapters === []) {
                // A lesson before any chapter: synthesise a container so no
                // content is orphaned and therefore unreachable by retrieval.
                $chapters[] = [
                    'number' => '1',
                    'title' => 'Untitled Unit',
                    'start_page' => (int) $candidate['page'],
                    'end_page' => $pageCount,
                    'lessons' => [],
                ];
            }

            $chapters[array_key_last($chapters)]['lessons'][] = [
                'number' => (string) $candidate['number'],
                'title' => (string) $candidate['title'],
                'start_page' => (int) $candidate['page'],
                'end_page' => $pageCount,
            ];
        }

        return $this->closePageRanges($chapters, $pageCount);
    }

    /**
     * Each node ends where the next one begins. Contiguous, non-overlapping
     * ranges are what let a chunk resolve to exactly one lesson.
     *
     * @param  list<array<string, mixed>>  $chapters
     * @return list<array<string, mixed>>
     */
    private function closePageRanges(array $chapters, int $pageCount): array
    {
        $count = count($chapters);

        foreach ($chapters as $i => $chapter) {
            $chapters[$i]['end_page'] = $i + 1 < $count
                ? max($chapter['start_page'], $chapters[$i + 1]['start_page'] - 1)
                : $pageCount;

            $lessons = $chapter['lessons'];
            $lessonCount = count($lessons);

            foreach ($lessons as $j => $lesson) {
                $lessons[$j]['end_page'] = $j + 1 < $lessonCount
                    ? max($lesson['start_page'], $lessons[$j + 1]['start_page'] - 1)
                    : $chapters[$i]['end_page'];
            }

            $chapters[$i]['lessons'] = $lessons;
        }

        return $chapters;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['level'].'|'.$candidate['number'];

            // A heading repeated on later pages is a running header; the first
            // occurrence is the real one.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        usort($unique, static fn (array $a, array $b): int => $a['page'] <=> $b['page']);

        return $unique;
    }

    /** @param  list<string>  $lines */
    private function firstBodyLine(array $lines, int $headingIndex): string
    {
        for ($i = $headingIndex + 1; $i < min($headingIndex + 5, count($lines)); $i++) {
            $line = trim($lines[$i]);

            if (mb_strlen($line) > 30) {
                return mb_substr($line, 0, 160);
            }
        }

        return '';
    }
}
