<?php

declare(strict_types=1);

namespace App\Domain\Documents\Contracts;

/**
 * Turns a composed document into the bytes of one file format.
 *
 * The composer decides what a document says; an exporter decides only how it
 * is encoded. Keeping that split is what lets a paper leave as PDF, DOCX, XLSX
 * or JSON without four descriptions of the same paper existing anywhere.
 */
interface DocumentExporter
{
    /** Matches the document_format enum. */
    public function format(): string;

    public function mimeType(): string;

    public function extension(): string;

    /**
     * @param  array{payload: array<string, mixed>, title: string, html: string}  $composed
     */
    public function export(array $composed): string;
}
