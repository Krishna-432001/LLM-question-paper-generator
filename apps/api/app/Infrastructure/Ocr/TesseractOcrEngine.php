<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Ingestion\Contracts\OcrEngine;
use App\Domain\Ingestion\DTOs\ExtractedPage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Tesseract 5 with the Tamil traineddata.
 *
 * The cheap tier and the no-vendor-lock-in escape hatch. Accuracy on printed
 * Tamil textbooks is roughly 88–93% against Document AI's 97–99%, which is why
 * this is the fallback rather than the default — but it costs compute only,
 * and it keeps the platform runnable with no external OCR dependency at all.
 */
final class TesseractOcrEngine implements OcrEngine
{
    public function __construct(
        private readonly string $binary = 'tesseract',
        private readonly string $rasteriser = 'pdftoppm',
        private readonly int $dpi = 300,
    ) {}

    public function name(): string
    {
        return 'tesseract';
    }

    public function isAvailable(): bool
    {
        $process = new Process([$this->binary, '--version']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    /** @return list<ExtractedPage> */
    public function extract(string $absolutePath, array $languageHints = ['en']): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Tesseract is not installed. Install it with the "tam" language data, '
                .'or switch OCR_DRIVER to document_ai.'
            );
        }

        $workDir = $this->makeWorkDir();

        try {
            $images = $this->rasterise($absolutePath, $workDir);
            $pages = [];

            foreach ($images as $index => $image) {
                $pages[] = $this->recognise($image, $index + 1, $languageHints);
            }

            return $pages;
        } finally {
            $this->cleanUp($workDir);
        }
    }

    /** @return list<string> */
    private function rasterise(string $pdf, string $workDir): array
    {
        $process = new Process([
            $this->rasteriser, '-r', (string) $this->dpi, '-png', $pdf, "{$workDir}/page",
        ]);
        $process->setTimeout((int) config('ingestion.extract_timeout_seconds', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Rasterising the PDF failed: '.$process->getErrorOutput());
        }

        $images = glob("{$workDir}/page*.png") ?: [];
        sort($images, SORT_NATURAL);

        return array_values($images);
    }

    /** @param  list<string>  $languageHints */
    private function recognise(string $image, int $pageNumber, array $languageHints): ExtractedPage
    {
        $languages = implode('+', array_map(
            static fn (string $code): string => match ($code) {
                'ta' => 'tam', 'te' => 'tel', 'ml' => 'mal',
                'hi', 'mr' => 'hin', 'kn' => 'kan', 'bn' => 'ben',
                'gu' => 'guj', 'or' => 'ori', 'pa' => 'pan', 'ur' => 'urd',
                default => 'eng',
            },
            $languageHints,
        ));

        $page = $this->run($image, $pageNumber, $languages);

        // Automatic layout analysis drops short lines it takes for noise —
        // on an MCQ page, "(B) Tiger" while keeping "(A) Lion". Uneven option
        // labels give that away, so only such a page pays for a second pass
        // as one column; a two-column sheet the first pass read whole keeps
        // its first reading, which the second could only splice.
        if (! self::optionLabelsUneven($page->text)) {
            return $page;
        }

        $column = $this->run($image, $pageNumber, $languages, ['--psm', '4']);

        return self::optionLabelCount($column->text) > self::optionLabelCount($page->text) ? $column : $page;
    }

    /** @param  list<string>  $layout  extra Tesseract arguments */
    private function run(string $image, int $pageNumber, string $languages, array $layout = []): ExtractedPage
    {
        // TSV output carries a per-word confidence, which is what lets us
        // quarantine a page rather than trust it blindly.
        $process = new Process([
            $this->binary, $image, 'stdout', '-l', $languages, '--dpi', (string) $this->dpi, ...$layout, 'tsv',
        ]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            return new ExtractedPage($pageNumber, '', 0.0, $this->name());
        }

        return $this->parseTsv($process->getOutput(), $pageNumber);
    }

    /**
     * Option labels per slot: (A)/அ) count as the first option, and so on.
     *
     * @return array{int, int, int, int}
     */
    public static function optionLabels(string $text): array
    {
        $slots = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'அ' => 0, 'ஆ' => 1, 'இ' => 2, 'ஈ' => 3];
        $counts = [0, 0, 0, 0];

        preg_match_all('/(?:^|\s)\(?\s*([A-Dஅஆஇஈ])\s*\)/u', $text, $matches);

        foreach ($matches[1] as $label) {
            $counts[$slots[$label]]++;
        }

        return $counts;
    }

    public static function optionLabelCount(string $text): int
    {
        return array_sum(self::optionLabels($text));
    }

    /** Some question on the page lost an option line: a slot fell behind. */
    public static function optionLabelsUneven(string $text): bool
    {
        $counts = self::optionLabels($text);

        return max($counts) > 0 && min($counts) !== max($counts);
    }

    private function parseTsv(string $tsv, int $pageNumber): ExtractedPage
    {
        $words = [];
        $confidences = [];
        $lastLine = null;

        foreach (explode("\n", $tsv) as $i => $row) {
            if ($i === 0 || trim($row) === '') {
                continue;
            }

            $cols = explode("\t", $row);

            if (count($cols) < 12) {
                continue;
            }

            $confidence = (float) $cols[10];
            $word = trim($cols[11]);

            if ($word === '' || $confidence < 0) {
                continue;
            }

            // Columns 2-4 identify block/paragraph/line: a change means a
            // newline, which preserves the layout the chunker relies on.
            $lineKey = $cols[2].'/'.$cols[3].'/'.$cols[4];

            if ($lastLine !== null && $lineKey !== $lastLine) {
                $words[] = "\n";
            }

            $words[] = $word;
            $confidences[] = $confidence / 100;
            $lastLine = $lineKey;
        }

        $text = str_replace(" \n ", "\n", implode(' ', $words));

        return new ExtractedPage(
            pageNumber: $pageNumber,
            text: trim($text),
            confidence: $confidences === [] ? 0.0 : array_sum($confidences) / count($confidences),
            engine: $this->name(),
        );
    }

    private function makeWorkDir(): string
    {
        $base = rtrim((string) (config('ingestion.tesseract.work_dir') ?: sys_get_temp_dir()), '/\\');
        $dir = $base.'/examforge-ocr-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private function cleanUp(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
