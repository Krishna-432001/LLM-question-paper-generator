<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers\Exceptions;

use App\Domain\Jobs\PermanentFailure;
use RuntimeException;

/**
 * An uploaded paper that cannot become a test, for a reason the uploader can
 * act on: no text and no OCR to get any, no numbered four-option questions in
 * what came out, or nowhere for the test to belong.
 *
 * Permanent, because reading the same bytes again gives the same answer. The
 * message is shown to the person who uploaded the file, so it says what to do
 * rather than what went wrong inside.
 */
final class PracticePaperException extends RuntimeException implements PermanentFailure
{
    public static function noTextReader(): self
    {
        return new self(
            'pdftotext is not available, so the PDF cannot be read. '
            .'Install poppler-utils (it provides pdftotext) and upload the paper again.'
        );
    }

    public static function scannedWithoutOcr(): self
    {
        return new self(
            'This PDF has no text layer (it looks like a scan), and no OCR engine is available to read it. '
            .'Install Tesseract with the Tamil language data and set OCR_DRIVER=tesseract, '
            .'or upload a PDF whose text can be selected.'
        );
    }

    public static function noQuestions(bool $afterOcr = false): self
    {
        return new self(
            ($afterOcr ? 'OCR read the pages, but no' : 'No')
            .' multiple-choice questions could be found in this PDF. Questions must be numbered '
            .'(1. or 1)) and have four options labelled (A)-(D) or அ) ஆ) இ) ஈ).'
        );
    }

    public static function noExam(): self
    {
        return new self(
            'No exam is set up yet, and every test has to belong to one. Create an exam first, then upload the paper.'
        );
    }

    public static function examNotAvailable(): self
    {
        return new self('That exam does not exist or is not active.');
    }

    public static function unreadableKey(): self
    {
        return new self(
            'No answers could be read. Write one entry per question, for example "1-A 2-C 3-B" '
            .'(அ ஆ இ ஈ also work), or send a map such as {"1": "A", "2": "C"}.'
        );
    }
}
