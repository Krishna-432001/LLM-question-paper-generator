<?php

declare(strict_types=1);

namespace App\Http\Requests\Sources;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->can('sources.create') ?? false)) {
            return false;
        }

        // Shared with every tenant, so only a platform admin may publish it.
        return ! $this->boolean('platform_content') || $this->user()->isPlatformAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'max:'.(int) (config('examforge.uploads.max_bytes') / 1024),
                // mimetypes validates the sniffed type, not the extension —
                // a renamed .exe fails here.
                'mimetypes:'.implode(',', config('examforge.uploads.allowed_mime')),
            ],
            'kind' => ['required', Rule::in([
                'textbook', 'reference_book', 'syllabus', 'previous_paper',
                'government_document', 'notes', 'current_affairs', 'other',
            ])],
            'title' => ['required', 'string', 'max:255'],
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'rights_basis' => ['required', Rule::in([
                'public_domain', 'govt_open', 'licensed', 'owned', 'fair_use',
            ])],
            'platform_content' => ['sometimes', 'boolean'],
            'meta' => ['sometimes', 'array'],
            'meta.board' => ['sometimes', 'nullable', 'string', 'max:120'],
            'meta.standard' => ['sometimes', 'nullable', 'string', 'max:20'],
            'meta.subject_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'meta.publisher' => ['sometimes', 'string', 'max:160'],
            'meta.edition' => ['sometimes', 'string', 'max:60'],
            'meta.publication_year' => ['sometimes', 'integer', 'between:1800,2100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'platform_content.boolean' => 'platform_content must be true or false.',
            'file.mimetypes' => 'Only PDF, image and DOCX uploads are accepted.',
        ];
    }
}
