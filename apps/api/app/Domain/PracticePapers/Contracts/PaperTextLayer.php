<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers\Contracts;

use App\Infrastructure\Ocr\PopplerPaperTextLayer;
use Illuminate\Container\Attributes\Bind;

/**
 * The text a question-paper PDF already carries, before any OCR.
 *
 * An interface rather than PopplerTextExtractor itself for two reasons. A
 * question paper is worth reading more than one way (see readings()), and the
 * tests need to hand the parser a known page without a pdftotext binary on
 * the machine running them.
 */
#[Bind(PopplerPaperTextLayer::class)]
interface PaperTextLayer
{
    public function isAvailable(): bool;

    /**
     * Each way of reading the text layer worth trying, page by page.
     *
     * Two-column MCQ papers come out interleaved when the layout is
     * preserved, and single-column ones can lose their inline option rows
     * when it is not, so the caller parses every reading and keeps the one
     * that yields the most questions.
     *
     * @return array<string, list<string>>  reading name => page texts in order
     */
    public function readings(string $absolutePath): array;
}
