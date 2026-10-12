<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Documents\RenderDocumentAction;
use App\Domain\Documents\Contracts\DocumentExporter;
use App\Domain\Documents\Contracts\PdfRenderer;
use App\Domain\Documents\DocumentComposer;
use App\Infrastructure\Export\DocxExporter;
use App\Infrastructure\Export\JsonExporter;
use App\Infrastructure\Export\XlsxExporter;
use App\Infrastructure\Pdf\FakePdfRenderer;
use App\Infrastructure\Pdf\MpdfRenderer;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentComposer::class);

        $this->app->singleton(PdfRenderer::class, function (): PdfRenderer {
            return match (config('pdf.driver')) {
                'fake' => new FakePdfRenderer,
                'mpdf' => new MpdfRenderer($this->tempPath()),
                default => throw new RuntimeException(
                    'Unknown PDF driver: '.config('pdf.driver')
                ),
            };
        });

        /*
        | Exporters keyed by format. Registered as a map rather than resolved
        | by name inside the action, so an unknown format fails at the edge
        | with a clear message instead of somewhere deep in rendering.
        */
        $this->app->singleton(RenderDocumentAction::class, fn ($app) => new RenderDocumentAction(
            $app->make(DocumentComposer::class),
            $app->make(PdfRenderer::class),
            collect([JsonExporter::class, DocxExporter::class, XlsxExporter::class])
                ->map(fn (string $class): DocumentExporter => $app->make($class))
                ->keyBy(fn (DocumentExporter $e): string => $e->format())
                ->all(),
        ));
    }

    private function tempPath(): string
    {
        $path = config('pdf.temp_path');

        // mPDF writes font caches here and fails opaquely if the directory is
        // missing, so it is created rather than assumed.
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }
}
