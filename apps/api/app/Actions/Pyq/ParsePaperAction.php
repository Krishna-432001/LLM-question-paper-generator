<?php

declare(strict_types=1);

namespace App\Actions\Pyq;

use App\Domain\Ingestion\DTOs\ExtractedPage;
use App\Domain\Ingestion\SourceScanner;
use App\Domain\Ingestion\TextNormalizer;
use App\Domain\Pyq\DTOs\ParsedPyq;
use App\Domain\Pyq\PyqParser;
use App\Models\PreviousYearPaper;
use App\Models\PreviousYearQuestion;
use App\Models\Source;
use App\Infrastructure\Ocr\PopplerTextExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads a previous-year paper PDF into rows.
 *
 * The extraction and normalisation steps are the same ones the textbook
 * pipeline uses, which matters more than it sounds: the Tamil repairs written
 * for Samacheer readers — the U+FFFD damage between a consonant and its vowel
 * sign, letter-spaced justification — apply just as much to a Tamil question
 * paper, and duplicating that logic here would mean fixing it twice.
 */
final class ParsePaperAction
{
    public function __construct(
        private readonly PopplerTextExtractor $extractor,
        private readonly TextNormalizer $normalizer,
        private readonly PyqParser $parser,
        private readonly SourceScanner $scanner,
    ) {}

    /**
     * @return array{parsed: int, skipped: int, keyed: int, pages: int}
     */
    public function execute(PreviousYearPaper $paper, Source $source): array
    {
        if (! $this->extractor->isAvailable()) {
            throw new RuntimeException(
                'pdftotext is not available, so a paper PDF cannot be read. '
                .'Install poppler-utils, or enter the questions manually.'
            );
        }

        // Papers skip ProcessSource, so they would otherwise skip its scan.
        $this->scanner->ensureScanned($source);
        $source->refresh();

        $pages = $this->extractor->extract($this->localPath($source));

        // The extractor yields ExtractedPage objects, not strings, so the
        // page text is taken off the DTO before normalising.
        $normalized = array_map(
            fn (ExtractedPage $page): string => $this->normalizer->normalizePage($page->text),
            $pages,
        );

        $parsed = $this->parser->parse($normalized);

        // Every question the parser could read, in one transaction: a paper
        // half-written is worse than one not written, because the frequency
        // analysis downstream would treat the missing half as never asked.
        DB::transaction(function () use ($paper, $parsed, $source): void {
            $paper->questions()->delete();

            foreach ($parsed as $question) {
                PreviousYearQuestion::query()->create(
                    $question->toArray() + [
                        'organization_id' => $paper->organization_id,
                        'paper_id' => $paper->getKey(),
                        'question_hash' => PreviousYearQuestion::hashFor($question->stem),
                        'question_type' => 'mcq4',
                        'mapping_source' => 'unmapped',
                    ],
                );
            }

            $paper->forceFill([
                'source_id' => $source->getKey(),
                'total_questions' => count($parsed),
                'parsed_questions' => count($parsed),
                // Parsed, not analysed: the questions still carry no topic, so
                // they contribute nothing to frequency until they are mapped.
                'status' => 'parsed',
            ])->save();
        });

        return [
            'pages' => count($pages),
            'parsed' => count($parsed),
            // Reported rather than hidden. A paper of 100 questions that yields
            // 61 means the parser lost 39, and the admin needs to see that
            // before the analysis is trusted.
            'skipped' => max(0, $this->highestNumber($parsed) - count($parsed)),
            'keyed' => count(array_filter(
                $parsed,
                static fn (ParsedPyq $q): bool => $q->correctOption !== null,
            )),
        ];
    }

    /** @param  list<ParsedPyq>  $parsed */
    private function highestNumber(array $parsed): int
    {
        return $parsed === []
            ? 0
            : max(array_map(static fn (ParsedPyq $q): int => $q->number, $parsed));
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

        $temp = sys_get_temp_dir().'/examforge-pyq-'.$source->content_sha256.'.pdf';
        file_put_contents($temp, $disk->get($source->storage_path));

        return $temp;
    }
}
