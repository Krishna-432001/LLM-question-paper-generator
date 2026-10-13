<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers\DTOs;

use App\Domain\Pyq\DTOs\ParsedPyq;

/**
 * What came out of one PDF: the questions in paper order, and what the reader
 * noticed along the way that the student should be told about.
 */
final class PaperReading
{
    /**
     * @param  list<ParsedPyq>  $questions
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $questions,
        public readonly int $pages,
        public readonly array $warnings,
        /** No questions and almost no text: a scan, which OCR may still read. */
        public readonly bool $looksScanned,
        public readonly bool $ocr = false,
    ) {}
}
