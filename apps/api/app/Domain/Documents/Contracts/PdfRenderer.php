<?php

declare(strict_types=1);

namespace App\Domain\Documents\Contracts;

interface PdfRenderer
{
    /**
     * Render an HTML document to PDF bytes.
     *
     * @param  array<string, mixed>  $options  locale, title, footer
     * @return array{content: string, pages: int}
     */
    public function render(string $html, array $options = []): array;

    public function name(): string;
}
