<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ingestion\DTOs\ExtractedPage;
use App\Domain\Ingestion\StructureDetector;
use App\Domain\Ingestion\TextNormalizer;
use Tests\TestCase;

/**
 * Regression cover for reading a real Tamil state-board textbook.
 *
 * A 90-page Samacheer reader produced exactly one chapter spanning the whole
 * book. Three separate defects combined to cause it, and each is pinned here,
 * because every one of them fails silently: nothing errors, the pipeline
 * reports success, and the damage only shows up as a useless citation weeks
 * later.
 */
final class TamilStructureTest extends TestCase
{
    public function test_it_detects_units_numbered_with_tamil_words(): void
    {
        // "இயல் ஒன்று" — unit one. Tamil textbooks never write "இயல் 1", and a
        // digits-only pattern therefore finds no chapters in the entire corpus
        // this platform exists to read.
        $result = app(StructureDetector::class)->detect([
            new ExtractedPage(pageNumber: 9, text: "இயல் ஒன்று தமிழ்த்தேன்\nபாடல் வரிகள் இங்கே தொடங்குகின்றன.", confidence: 1.0),
            new ExtractedPage(pageNumber: 33, text: "இயல் இரண்டு இயற்கை இன்பம்\nஇயற்கை பற்றிய பாடம்.", confidence: 1.0),
        ]);

        $chapters = array_values(array_filter(
            $result['candidates'],
            static fn (array $c): bool => $c['level'] === 'chapter',
        ));

        $this->assertCount(2, $chapters);

        // The word is normalised to a number, because everything downstream —
        // ordering, citations, the structure editor — expects one.
        $this->assertSame('1', $chapters[0]['number']);
        $this->assertSame('தமிழ்த்தேன்', $chapters[0]['title']);
        $this->assertSame('2', $chapters[1]['number']);
        $this->assertSame('இயற்கை இன்பம்', $chapters[1]['title']);
    }

    public function test_a_word_boundary_would_not_survive_a_tamil_vowel_sign(): void
    {
        // The original pattern ended the number with \b. PCRE builds \b from
        // \w, which excludes combining marks even under /u, and every Tamil
        // ordinal closes with one (ஒன்று ends U+0BC1). The boundary therefore
        // never existed and the match never fired.
        $withBoundary = '/(ஒன்று)\b/u';
        $withLookahead = '/(ஒன்று)(?=[\s\-–—:.]|$)/u';

        $this->assertSame(0, preg_match($withBoundary, 'இயல் ஒன்று தமிழ்த்தேன்'));
        $this->assertSame(1, preg_match($withLookahead, 'இயல் ஒன்று தமிழ்த்தேன்'));
    }

    public function test_english_numbering_still_works_and_stays_bounded(): void
    {
        $result = app(StructureDetector::class)->detect([
            new ExtractedPage(pageNumber: 1, text: "Unit 1 — The Living World\nCells are the unit of life.", confidence: 1.0),
            new ExtractedPage(pageNumber: 9, text: "Chapter 12: Motion\nSpeed is distance over time.", confidence: 1.0),
            // Must NOT be read as unit 12: the terminator has to stay strict.
            new ExtractedPage(pageNumber: 20, text: "Unit 123 is not a heading at all\nBody text.", confidence: 1.0),
        ]);

        $numbers = array_map(
            static fn (array $c): string => $c['number'],
            array_values(array_filter(
                $result['candidates'],
                static fn (array $c): bool => $c['level'] === 'chapter',
            )),
        );

        $this->assertSame(['1', '12'], $numbers);
    }

    public function test_the_chunker_recognises_every_exercise_heading_the_detector_does(): void
    {
        // These lived as two hand-maintained copies. The detector learned the
        // Tamil headings; the chunker — which is what actually sets
        // is_exercise — did not, so 52 of 68 chunks in a real reader were
        // book-back exercises passed to generation as teaching prose. The
        // model then produced the textbook's own questions back, options and
        // all. Both now build from one list, and this asserts they agree.
        $headings = ['மதிப்பீடு', 'கற்பவை கற்றபின்', 'குறுவினா', 'சிந்தனை வினா', 'Exercises'];

        $detectorPattern = '/^\s*('.StructureDetector::EXERCISE_HEADINGS.')\s*[.:]?\s*$/iu';
        $chunkerPattern = '/^[ \t]*('.StructureDetector::EXERCISE_HEADINGS.')[ \t]*[.:]?[ \t]*$/imu';

        foreach ($headings as $heading) {
            $this->assertSame(1, preg_match($detectorPattern, $heading), "detector missed {$heading}");
            $this->assertSame(1, preg_match($chunkerPattern, $heading), "chunker missed {$heading}");
        }

        // Teaching content that merely looks section-like must still be kept.
        $this->assertSame(0, preg_match($chunkerPattern, 'பாடலின் பொருள்'));
    }

    public function test_it_strips_the_publisher_imprint_stamped_on_every_page(): void
    {
        $normalizer = app(TextNormalizer::class);

        // The site watermark is glued to the end of a real content line, and
        // the InDesign filename carries a per-file page range — so neither ever
        // repeats cleanly enough for running-furniture detection to catch it.
        $cleaned = $normalizer->normalizePage(
            "இயல்  www.tntextbooks.in\nஒன்று      தமிழ்த்தேன்\n6th Std Tamil Term 1 Pages 1-24.indd 1",
        );

        $this->assertStringNotContainsString('tntextbooks', $cleaned);
        $this->assertStringNotContainsString('.indd', $cleaned);

        // The heading itself must survive intact, on one line, ready to match.
        $this->assertStringContainsString('இயல்', $cleaned);
        $this->assertStringContainsString('தமிழ்த்தேன்', $cleaned);
    }
}
