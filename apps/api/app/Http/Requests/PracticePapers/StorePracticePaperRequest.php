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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'max:'.(int) (config('examforge.uploads.max_bytes') / 1024),
                // The sniffed type, not the extension: a renamed file fails.
                'mimetypes:application/pdf',
            ],
            'title' => ['required', 'string', 'max:200'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'exam_id' => ['sometimes', 'nullable', 'uuid'],
            'language' => ['sometimes', 'nullable', 'in:en,ta'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Upload the question paper as a PDF.',
            'file.required' => 'Choose the question paper PDF to upload.',
        ];
    }
}
