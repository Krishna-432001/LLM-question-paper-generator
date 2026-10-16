<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

use App\Domain\Documents\Contracts\DocumentExporter;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/**
 * A paper a teacher can edit.
 *
 * The PDF is the artefact that gets printed; this is the one that gets
 * adjusted before printing. It is written from the composed payload rather
 * than by converting the PDF, because converting a laid-out PDF back into a
 * document produces text boxes nobody can edit.
 */
final class DocxExporter implements DocumentExporter
{
    public function format(): string
    {
        return 'docx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function extension(): string
    {
        return 'docx';
    }

    public function export(array $composed): string
    {
        // Tamil and other Indic text needs a font that actually carries the
        // glyphs; Word substitutes silently and the result looks like boxes.
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $word->setDefaultFontName('Nirmala UI');
        $word->setDefaultFontSize(11);

        $section = $word->addSection();
        $payload = $composed['payload'];
        $meta = $payload['meta'] ?? [];

        $section->addText((string) ($meta['exam'] ?? ''), ['bold' => true, 'size' => 13], ['alignment' => 'center']);
        $section->addText((string) ($meta['title'] ?? ''), ['bold' => true, 'size' => 15], ['alignment' => 'center']);

        // Headings in the paper's own language(s), as the composer supplies
        // them; English for payloads stored before it did.
        $label = static fn (string $key, string $english): string => (string) ($meta['labels'][$key] ?? $english);

        $line = array_filter([
            isset($meta['duration_minutes']) ? $label('time', 'Time').": {$meta['duration_minutes']} ".$label('minutes', 'min') : null,
            isset($meta['total_questions']) ? $label('questions', 'Questions').": {$meta['total_questions']}" : null,
            isset($meta['total_marks']) ? $label('marks', 'Marks').': '.(float) $meta['total_marks'] : null,
        ]);

        if ($line !== []) {
            $section->addText(implode('     ', $line), ['size' => 10], ['alignment' => 'center']);
        }

        $section->addTextBreak();
        $part = null;

        foreach ($payload['questions'] ?? [] as $question) {
            // A mock in parts names each part where it begins, as on the PDF.
            if (($question['section'] ?? null) !== null && $question['section'] !== $part) {
                $part = $question['section'];
                $section->addText((string) $part, ['bold' => true, 'size' => 12], ['alignment' => 'center', 'spaceBefore' => 120, 'spaceAfter' => 120]);
            }

            $section->addText(
                // A primary with no printable text is marked, never left as a
                // bare number nor filled from another language.
                $question['number'].'. '.(string) ($question['text'] ?? '[translation unavailable]'),
                ['bold' => false],
                ['spaceAfter' => 60],
            );

            // A bilingual paper's other language, beneath the primary as on
            // the PDF. Only the versions after the first: the first is the
            // primary already printed above.
            $others = array_slice(array_values($question['texts'] ?? []), 1);

            foreach ($others as $other) {
                if (($other['text'] ?? '') !== '' && $other['text'] !== $question['text']) {
                    $section->addText('     '.$other['text'], ['color' => '444444'], ['spaceAfter' => 60]);
                }
            }

            foreach ($question['options'] ?? [] as $key => $text) {
                $alternates = array_filter(
                    array_map(static fn (array $o): ?string => $o['options'][$key] ?? null, $others),
                    static fn (?string $v): bool => $v !== null && $v !== '' && $v !== $text,
                );

                $section->addText(
                    "     ({$key}) {$text}".($alternates === [] ? '' : ' / '.implode(' / ', $alternates)),
                    [],
                    ['spaceAfter' => 0],
                );
            }

            $section->addTextBreak();
        }

        if (! empty($payload['answers'])) {
            $section->addPageBreak();
            $section->addText($label('answer_key', 'Answer Key'), ['bold' => true, 'size' => 14]);

            foreach ($payload['answers'] as $answer) {
                $section->addText("{$answer['number']}. {$answer['answer']}", [], ['spaceAfter' => 0]);
            }
        }

        // Written to a temp file and read back: PhpWord writers target a path
        // or php://output, and php://output inside a request would append the
        // document to the HTTP response body.
        $temp = tempnam(sys_get_temp_dir(), 'ef-docx-');

        try {
            $word->save($temp, 'Word2007');

            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
        }
    }
}
