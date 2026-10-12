<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

use App\Domain\Documents\Contracts\DocumentExporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The paper as a grid, for bulk review and for handing to people who work in
 * a spreadsheet rather than a review console.
 *
 * One row per question with the answer in its own column, because the whole
 * point of this format is sorting and filtering — which a prose layout makes
 * impossible.
 */
final class XlsxExporter implements DocumentExporter
{
    public function format(): string
    {
        return 'xlsx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function export(array $composed): string
    {
        $payload = $composed['payload'];

        // Answers are keyed by question number so the lookup is O(1) and, more
        // importantly, so a paper whose key is partial still exports cleanly
        // rather than shifting every answer up a row.
        $answers = [];

        foreach ($payload['answers'] ?? [] as $answer) {
            $answers[(int) $answer['number']] = $answer['answer'] ?? null;
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Questions');

        $headers = ['#', 'Question', 'A', 'B', 'C', 'D', 'Answer', 'Marks'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $row = 2;

        foreach ($payload['questions'] ?? [] as $question) {
            $options = $question['options'] ?? [];
            $number = (int) $question['number'];

            $sheet->fromArray([
                $number,
                (string) ($question['text'] ?? ''),
                $options['A'] ?? '', $options['B'] ?? '',
                $options['C'] ?? '', $options['D'] ?? '',
                $answers[$number] ?? '',
                $question['marks'] ?? 1,
            ], null, "A{$row}");

            // Stems are long; without wrapping every row is one unreadable line.
            $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);

            $row++;
        }

        foreach (['A' => 6, 'B' => 70, 'C' => 22, 'D' => 22, 'E' => 22, 'F' => 22, 'G' => 9, 'H' => 8] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $temp = tempnam(sys_get_temp_dir(), 'ef-xlsx-');

        try {
            (new Xlsx($spreadsheet))->save($temp);

            return (string) file_get_contents($temp);
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($temp);
        }
    }
}
