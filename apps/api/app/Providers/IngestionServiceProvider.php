<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ingestion\Chunker;
use App\Domain\Ingestion\Contracts\EmbeddingProvider;
use App\Domain\Ingestion\Contracts\OcrEngine;
use App\Domain\Ingestion\Contracts\VirusScanner;
use App\Infrastructure\Antivirus\ClamAvScanner;
use App\Infrastructure\Antivirus\FakeVirusScanner;
use App\Infrastructure\Antivirus\NullVirusScanner;
use App\Domain\Ingestion\IngestionPipeline;
use App\Domain\Ingestion\StructureDetector;
use App\Domain\Ingestion\TextNormalizer;
use App\Infrastructure\Embeddings\FakeEmbeddingProvider;
use App\Infrastructure\Embeddings\HttpEmbeddingProvider;
use App\Infrastructure\Ocr\FakeOcrEngine;
use App\Infrastructure\Ocr\PopplerTextExtractor;
use App\Infrastructure\Ocr\TesseractOcrEngine;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OcrEngine::class, fn (): OcrEngine => match (config('ingestion.ocr_driver')) {
            'fake' => new FakeOcrEngine,
            'tesseract' => new TesseractOcrEngine(
                binary: config('ingestion.tesseract.binary'),
                rasteriser: config('ingestion.poppler.pdftoppm'),
                dpi: (int) config('ingestion.tesseract.dpi'),
            ),
            default => throw new RuntimeException(
                'Unknown OCR driver: '.config('ingestion.ocr_driver')
            ),
        });

        $this->app->singleton(VirusScanner::class, function (): VirusScanner {
            $driver = config('ingestion.antivirus.driver');

            if ($driver === 'none' && $this->app->environment('production')) {
                throw new RuntimeException('AV_DRIVER=none is not allowed in production; configure clamav.');
            }

            return match ($driver) {
                'none' => new NullVirusScanner,
                'fake' => new FakeVirusScanner,
                'clamav' => new ClamAvScanner(
                    host: config('ingestion.antivirus.clamav.host'),
                    port: (int) config('ingestion.antivirus.clamav.port'),
                    timeoutSeconds: (int) config('ingestion.antivirus.clamav.timeout_seconds'),
                ),
                default => throw new RuntimeException("Unknown antivirus driver: {$driver}"),
            };
        });

        $this->app->singleton(EmbeddingProvider::class, fn (): EmbeddingProvider => match (config('embeddings.driver')) {
            'fake' => new FakeEmbeddingProvider((int) config('embeddings.dimensions')),
            'http' => new HttpEmbeddingProvider(
                endpoint: config('embeddings.endpoint'),
                model: config('embeddings.model'),
                dimensions: (int) config('embeddings.dimensions'),
                timeoutSeconds: (int) config('embeddings.timeout_seconds'),
            ),
            default => throw new RuntimeException(
                'Unknown embedding driver: '.config('embeddings.driver')
            ),
        });

        $this->app->singleton(PopplerTextExtractor::class, fn (): PopplerTextExtractor => new PopplerTextExtractor(
            config('ingestion.poppler.pdftotext')
        ));

        $this->app->singleton(TextNormalizer::class);
        $this->app->singleton(StructureDetector::class);

        $this->app->singleton(Chunker::class, fn (): Chunker => new Chunker(
            targetTokens: (int) config('ingestion.chunking.target_tokens'),
            maxTokens: (int) config('ingestion.chunking.max_tokens'),
            minTokens: (int) config('ingestion.chunking.min_tokens'),
            overlapRatio: (float) config('ingestion.chunking.overlap_ratio'),
        ));

        $this->app->singleton(IngestionPipeline::class);
    }
}
