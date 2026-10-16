<?php

declare(strict_types=1);

namespace App\Http\Requests\PracticePapers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Any signed-in user may upload a paper to practise on: it is private to them
 * and never reaches the shared bank, so it needs none of the content
 * permissions a textbook upload does.
 */
final class StorePracticePaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * A question paper is tens of pages, not a 600-page textbook. The cap is
     * well above any real paper and well below what would tie up a scan and
     * an OCR run for nothing.
     */
    public const MAX_KILOBYTES = 50 * 1024;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'max:'.self::MAX_KILOBYTES,
                // The sniffed type, not the extension: a renamed file fails.
                'mimetypes:application/pdf',
            ],
            'title' => ['required', 'string', 'max:200'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'exam_id' => ['sometimes', 'nullable', 'uuid'],
            'language' => ['sometimes', 'nullable', 'in:en,ta'],
            // Read the page images even though the PDF has text: its Tamil came out broken.
            'ocr' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Upload the question paper as a PDF.',
            'file.required' => 'Choose the question paper PDF to upload.',
            'file.max' => 'A question paper PDF can be at most 50 MB.',
        ];
    }
}
