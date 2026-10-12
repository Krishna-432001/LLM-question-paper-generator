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

        $line = array_filter([
            isset($meta['duration_minutes']) ? "Time: {$meta['duration_minutes']} min" : null,
            isset($meta['total_questions']) ? "Questions: {$meta['total_questions']}" : null,
            isset($meta['total_marks']) ? 'Marks: '.(float) $meta['total_marks'] : null,
        ]);

        if ($line !== []) {
            $section->addText(implode('     ', $line), ['size' => 10], ['alignment' => 'center']);
        }

        $section->addTextBreak();

        foreach ($payload['questions'] ?? [] as $question) {
            $section->addText(
                $question['number'].'. '.(string) ($question['text'] ?? ''),
                ['bold' => false],
                ['spaceAfter' => 60],
            );

            foreach ($question['options'] ?? [] as $key => $text) {
                $section->addText("     ({$key}) {$text}", [], ['spaceAfter' => 0]);
            }

            $section->addTextBreak();
        }

        if (! empty($payload['answers'])) {
            $section->addPageBreak();
            $section->addText('Answer Key', ['bold' => true, 'size' => 14]);

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
