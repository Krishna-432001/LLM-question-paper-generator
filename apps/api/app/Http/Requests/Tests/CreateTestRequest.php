<?php

declare(strict_types=1);

namespace App\Http\Requests\Tests;

use App\Models\Test;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tests.create.own')
            || $this->user()?->can('tests.create.org');
    }

    /**
     * Language ids are lower-cased before validation. Postgres compares
     * uuids case-insensitively and PHP compares strings exactly, so "the
     * same language in capitals" passed different:language_id and then
     * failed the database's own check as a 500.
     */
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
            // single: every question in language_id. bilingual: every question
            // in language_id and again in secondary_language_id, primary first.
            'language_mode' => ['sometimes', Rule::in(Test::LANGUAGE_MODES)],
            'secondary_language_id' => [
                'nullable',
                'required_if:language_mode,'.Test::MODE_BILINGUAL,
                'uuid',
                'exists:languages,id',
                'different:language_id',
            ],
            'type' => ['required', Rule::in([
                'daily', 'three_day', 'weekly', 'monthly', 'model',
                'prediction', 'topic_practice', 'custom', 'diagnostic',
            ])],
            // ('error_recovery' has its own endpoint: its questions are the
            // student's own mistakes, not a selection from the bank.)
            'question_count' => ['required', 'integer', 'between:5,200'],
            // A topic-wise test: whole syllabus units, single topics (each
            // with its sub-topics), or both. Omitted: the whole syllabus.
            'exam_unit_ids' => ['sometimes', 'array', 'max:50'],
            'exam_unit_ids.*' => ['uuid'],
            'exam_topic_ids' => ['sometimes', 'array', 'max:300'],
            'exam_topic_ids.*' => ['uuid'],
            'title' => ['sometimes', 'string', 'max:160'],
            'duration_minutes' => ['sometimes', 'integer', 'between:5,300'],
            'negative_marking' => ['sometimes', 'numeric', 'between:0,2'],
            'difficulty_mix' => ['sometimes', 'array'],
            'difficulty_mix.easy' => ['sometimes', 'numeric', 'between:0,1'],
            'difficulty_mix.medium' => ['sometimes', 'numeric', 'between:0,1'],
            'difficulty_mix.hard' => ['sometimes', 'numeric', 'between:0,1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'secondary_language_id.required_if' => 'A bilingual paper needs a second language.',
            'secondary_language_id.different' => 'The second language must be different from the main language.',
        ];
    }
}
