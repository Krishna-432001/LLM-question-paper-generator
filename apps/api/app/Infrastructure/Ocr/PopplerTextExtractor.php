<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Ingestion\DTOs\ExtractedPage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extracts an existing digital text layer with `pdftotext`.
 *
 * This is the single highest-leverage cost saving in ingestion: most modern
 * state-board PDFs already carry a text layer, and running OCR on them would
 * pay ~₹1.50/1000 pages for text that is already there — and would be *less*
 * accurate than the embedded text.
 */
final class PopplerTextExtractor
{
    public function __construct(private readonly string $binary = 'pdftotext') {}

    public function isAvailable(): bool
    {
        $process = new Process([$this->binary, '-v']);
        $process->setTimeout(15);
        $process->run();

        // pdftotext writes its banner to stderr and may exit non-zero on -v.
        return str_contains($process->getErrorOutput().$process->getOutput(), 'pdftotext');
    }

    public function pageCount(string $absolutePath): int
    {
        $process = new Process(['pdfinfo', $absolutePath]);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful()
            && preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $m) === 1) {
            return (int) $m[1];
        }

        // pdfinfo is not always shipped alongside pdftotext; fall back to
        // extracting everything and counting form feeds.
        return max(1, substr_count($this->rawText($absolutePath), "\f"));
    }

    /**
     * @return list<ExtractedPage>
     */
    public function extract(string $absolutePath): array
    {
        // -layout preserves column structure, which matters for two-column
        // textbooks: without it, reading order interleaves the columns.
        $text = $this->rawText($absolutePath);

        $pages = [];

        // pdftotext separates pages with a form feed.
        foreach (explode("\f", $text) as $index => $pageText) {
            $trimmed = rtrim($pageText);

            if ($index > 0 && $trimmed === '' && $index === substr_count($text, "\f")) {
                continue;   // trailing empty segment after the final form feed
            }

            $pages[] = new ExtractedPage(
                pageNumber: $index + 1,
                text: $trimmed,
                confidence: 1.0,   // an embedded text layer is not a guess
                engine: 'poppler',
            );
        }

        return $pages;
    }

    /**
     * Re-reads one page.
     *
     * A 600-page textbook that misread page 412 must not be re-extracted in
     * full to fix it: that is twenty minutes of work to repair one page, so in
     * practice nobody does it and the bad page stays. pdftotext takes a page
     * range, which makes the repair proportional to the damage.
     */
    public function extractPage(string $absolutePath, int $pageNumber): ExtractedPage
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException("Source file is not readable: {$absolutePath}");
        }

        $process = new Process([
            $this->binary, '-layout', '-enc', 'UTF-8',
            '-f', (string) $pageNumber, '-l', (string) $pageNumber,
            $absolutePath, '-',
        ]);

        $process->setTimeout((int) config('ingestion.extract_timeout_seconds', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "pdftotext failed on page {$pageNumber}: "
                .trim($process->getErrorOutput() ?: 'unknown error')
            );
        }

        return new ExtractedPage(
            pageNumber: $pageNumber,
            // The form feed that terminates the page is not part of its text.
            text: rtrim(str_replace("", '', $process->getOutput())),
            confidence: 1.0,
            engine: 'poppler',
        );
    }

    /**
     * Does this PDF carry a usable text layer, or does it need OCR?
     * The threshold is deliberately generous: a scanned book with a bad
     * auto-OCR layer should still be re-OCR'd rather than trusted.
     */
    public function hasUsableTextLayer(string $absolutePath): bool
    {
        $pages = $this->extract($absolutePath);

        if ($pages === []) {
            return false;
        }

        $withText = array_filter(
            $pages,
            static fn (ExtractedPage $p): bool => mb_strlen(trim($p->text)) >= 100,
        );

        return (count($withText) / count($pages))
            >= (float) config('ingestion.text_layer_page_ratio', 0.80);
    }

    private function rawText(string $absolutePath): string
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException("Source file is not readable: {$absolutePath}");
        }

        $process = new Process([$this->binary, '-layout', '-enc', 'UTF-8', $absolutePath, '-']);
        $process->setTimeout((int) config('ingestion.extract_timeout_seconds', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'pdftotext failed: '.trim($process->getErrorOutput() ?: 'unknown error')
            );
        }

        return $process->getOutput();
    }
}
