<?php

declare(strict_types=1);

namespace App\Http\Requests\Tests;

use App\Models\Test;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A full mock in the exam's own pattern. No question count, time or marks:
 * the exam decides those. A prediction mock is the same paper with its
 * questions weighted towards the topics past papers ask most.
 */
final class CreateExamPatternTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tests.create.own')
            || $this->user()?->can('tests.create.org');
    }

    /** Lower-cased for the same reason as CreateTestRequest: uuid comparison. */
    protected function prepareForValidation(): void
    {
        foreach (['language_id', 'secondary_language_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtolower($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'exam_id' => ['required', 'uuid', 'exists:exams,id'],
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'language_mode' => ['sometimes', Rule::in(Test::LANGUAGE_MODES)],
            'secondary_language_id' => [
                'nullable',
                'required_if:language_mode,'.Test::MODE_BILINGUAL,
                'uuid',
                'exists:languages,id',
                'different:language_id',
            ],
            'type' => ['sometimes', Rule::in(['model', 'prediction'])],
            'title' => ['sometimes', 'string', 'max:160'],
            // Build a shorter mock from what the bank holds when it cannot
            // fill the whole pattern yet.
            'allow_short' => ['sometimes', 'boolean'],
        ];
    }
}
