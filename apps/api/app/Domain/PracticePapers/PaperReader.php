<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers;

use App\Domain\Ingestion\Contracts\OcrEngine;
use App\Domain\Ingestion\DTOs\ExtractedPage;
use App\Domain\Ingestion\TextNormalizer;
use App\Domain\PracticePapers\Contracts\PaperTextLayer;
use App\Domain\PracticePapers\DTOs\PaperReading;
use App\Domain\PracticePapers\Exceptions\PracticePaperException;
use App\Domain\Pyq\PyqParser;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Turns an uploaded question-paper PDF into parsed questions.
 *
 * The same normaliser and parser the textbook pipeline and the PYQ parser
 * use, so the Tamil repairs and the bilingual handling are written once. What
 * this adds is the choice between readings, and the decision about whether
 * an empty result means "not a question paper" or "a scan that OCR can read".
 *
 * The caller must have run the malware scan first. Nothing here checks,
 * because by the time a path reaches pdftotext it is too late to.
 */
final class PaperReader
{
    /** Below this, a page has no text worth the name: a scan or a blank. */
    private const BLANK_PAGE_CHARACTERS = 20;

    /**
     * Below this average per page, a PDF that yielded no questions is treated
     * as a scan. The same 100 characters the ingestion pipeline uses to decide
     * whether a page carries a usable text layer.
     */
    private const TEXT_LAYER_CHARACTERS = 100;

    public function __construct(
        private readonly PaperTextLayer $textLayer,
        private readonly TextNormalizer $normalizer,
        private readonly PyqParser $parser,
    ) {}

    /**
     * Every reading of the text layer is parsed and the one that yields the
     * most questions wins; on a tie, the first (layout-preserving) one.
     */
    public function fromTextLayer(Source $source): PaperReading
    {
        if (! $this->textLayer->isAvailable()) {
            throw PracticePaperException::noTextReader();
        }

        $best = null;

        foreach ($this->textLayer->readings($this->localPath($source)) as $pages) {
            $reading = $this->read(array_values($pages), ocr: false);

            if ($best === null || count($reading->questions) > count($best->questions)) {
                $best = $reading;
            }
        }

        return $best ?? new PaperReading([], 0, [], looksScanned: true);
    }

    /**
     * An OCR engine that cannot even be constructed (an unknown driver in the
     * config) is as unavailable as one that is not installed.
     */
    public function ocrAvailable(): bool
    {
        try {
            return app(OcrEngine::class)->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Slow — minutes for a 40-page paper on Tesseract — so it runs on the OCR
     * queue, never inside the upload request.
     */
    public function fromOcr(Source $source): PaperReading
    {
        // Tamil first: TNPSC papers are bilingual, and Tesseract weighs the
        // first language it is given when a glyph could be either.
        $pages = app(OcrEngine::class)->extract($this->localPath($source), ['ta', 'en']);

        $reading = $this->read(
            array_map(static fn (ExtractedPage $page): string => $page->text, $pages),
            ocr: true,
        );

        $doubtful = array_values(array_map(
            static fn (ExtractedPage $page): int => $page->pageNumber,
            array_filter($pages, static fn (ExtractedPage $page): bool => $page->needsReview()),
        ));

        if ($doubtful === []) {
            return $reading;
        }

        return new PaperReading(
            questions: $reading->questions,
            pages: $reading->pages,
            warnings: [
                ...$reading->warnings,
                PaperWarning::make('ocr_unsure', ['pages' => implode(', ', $doubtful)]),
            ],
            looksScanned: $reading->looksScanned,
            ocr: true,
        );
    }

    /** @param  list<string>  $pages */
    private function read(array $pages, bool $ocr): PaperReading
    {
        $normalized = $this->normalizer->normalizePages(array_map($this->keepQuestionNumbers(...), $pages));
        $paper = $this->parser->read($normalized);
        $questions = $paper->questions;

        $blank = [];
        $characters = 0;

        foreach ($normalized as $i => $text) {
            $length = mb_strlen((string) preg_replace('/\s+/u', '', $text));
            $characters += $length;

            if ($length < self::BLANK_PAGE_CHARACTERS) {
                $blank[] = $i + 1;
            }
        }

        // What the parser noticed: parts numbered separately, a key that
        // could only be matched to some of them.
        $warnings = array_map(
            static fn (array $note): array => PaperWarning::make($note['code'], $note['params']),
            $paper->notes,
        );

        // Some pages read and some did not: a paper partly scanned, or with
        // its last pages as images. Whatever was on them is missing, and the
        // student should know before they wonder where question 80 went.
        if ($questions !== [] && $blank !== [] && count($blank) < count($normalized)) {
            $warnings[] = PaperWarning::make('pages_unreadable', ['pages' => implode(', ', $blank)]);
        }

        return new PaperReading(
            questions: $questions,
            pages: count($normalized),
            warnings: $warnings,
            looksScanned: $questions === []
                && $characters < self::TEXT_LAYER_CHARACTERS * max(1, count($normalized)),
            ocr: $ocr,
        );
    }

    /**
     * Some papers set the question number on a line of its own. The
     * normaliser drops any line that is only digits and punctuation as a page
     * number, which would weld question 12 onto question 11. Joining the lone
     * number to the line after it keeps the question.
     */
    private function keepQuestionNumbers(string $page): string
    {
        return (string) preg_replace('/^([ \t]*\d{1,3}[.)])[ \t]*\R[ \t]*(?=\S)/mu', '$1 ', $page);
    }

    private function localPath(Source $source): string
    {
        $disk = Storage::disk($source->storage_disk);

        if (! $disk->exists($source->storage_path)) {
            throw new RuntimeException("Stored object missing: {$source->storage_path}");
        }

        $path = $disk->path($source->storage_path);

        if (is_readable($path)) {
            return $path;
        }

        // Remote disks: pdftotext and Tesseract need a file on this machine.
        $temp = sys_get_temp_dir().'/examforge-paper-'.$source->content_sha256.'.pdf';
        file_put_contents($temp, $disk->get($source->storage_path));

        return $temp;
    }
}
