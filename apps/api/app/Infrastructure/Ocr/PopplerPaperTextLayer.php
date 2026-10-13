<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Ingestion\DTOs\ExtractedPage;
use App\Domain\PracticePapers\Contracts\PaperTextLayer;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * pdftotext, read twice.
 *
 * The layout reading comes from PopplerTextExtractor, the same one the
 * textbook pipeline and the PYQ parser use. The second reading drops -layout:
 * pdftotext then follows the content stream, which keeps the two columns of
 * a coaching-centre MCQ sheet apart instead of splicing question 1 and
 * question 26 onto one line.
 */
final class PopplerPaperTextLayer implements PaperTextLayer
{
    public function __construct(private readonly PopplerTextExtractor $extractor) {}

    public function isAvailable(): bool
    {
        return $this->extractor->isAvailable();
    }

    /** @return array<string, list<string>> */
    public function readings(string $absolutePath): array
    {
        $readings = [
            'layout' => array_map(
                static fn (ExtractedPage $page): string => $page->text,
                $this->extractor->extract($absolutePath),
            ),
        ];

        try {
            $readings['reading_order'] = $this->readingOrder($absolutePath);
        } catch (RuntimeException) {
            // The layout reading already succeeded; a second opinion that
            // fails is not a reason to refuse the paper.
        }

        return $readings;
    }

    /** @return list<string> */
    private function readingOrder(string $absolutePath): array
    {
        $process = new Process([
            (string) config('ingestion.poppler.pdftotext', 'pdftotext'),
            '-enc', 'UTF-8', $absolutePath, '-',
        ]);
        $process->setTimeout((int) config('ingestion.extract_timeout_seconds', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pdftotext failed: '.trim($process->getErrorOutput()));
        }

        $pages = explode("\f", $process->getOutput());

        // pdftotext ends the last page with a form feed too.
        if (count($pages) > 1 && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        return array_map(static fn (string $page): string => rtrim($page), $pages);
    }
}
