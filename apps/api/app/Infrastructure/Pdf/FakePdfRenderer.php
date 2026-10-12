<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Domain\Documents\Contracts\PdfRenderer;

/**
 * Produces a structurally valid, single-page PDF without a rendering engine.
 *
 * Tests assert the pipeline — payload building, hashing, reuse, storage,
 * download — not glyph placement. Rendering a real 100-question paper in every
 * test would dominate the suite's runtime for no added confidence.
 */
final class FakePdfRenderer implements PdfRenderer
{
    public function name(): string
    {
        return 'fake';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{content: string, pages: int}
     */
    public function render(string $html, array $options = []): array
    {
        $title = (string) ($options['title'] ?? 'Document');
        $length = mb_strlen(strip_tags($html));

        // A minimal but real PDF: file-signature checks and byte-size
        // assertions behave the same as they would against mPDF output.
        $body = "BT /F1 12 Tf 40 780 Td ({$this->escape($title)}) Tj ET";

        $pdf = "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]"
            ."/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
            .'4 0 obj<</Length '.strlen($body).">>>stream\n{$body}\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF\n"
            // Padding keeps size proportional to content, so "did the payload
            // change?" assertions are meaningful.
            .str_repeat(' ', min(4096, $length));

        return ['content' => $pdf, 'pages' => 1];
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
