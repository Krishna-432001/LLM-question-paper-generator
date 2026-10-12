<?php

declare(strict_types=1);

namespace App\Http\Requests\Tests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tests.create.own')
            || $this->user()?->can('tests.create.org');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'exam_id' => ['required', 'uuid', 'exists:exams,id'],
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'type' => ['required', Rule::in([
                'daily', 'three_day', 'weekly', 'monthly', 'model',
                'prediction', 'topic_practice', 'custom', 'diagnostic',
            ])],
            'question_count' => ['required', 'integer', 'between:5,200'],
            'title' => ['sometimes', 'string', 'max:160'],
            'duration_minutes' => ['sometimes', 'integer', 'between:5,300'],
            'negative_marking' => ['sometimes', 'numeric', 'between:0,2'],
            'difficulty_mix' => ['sometimes', 'array'],
            'difficulty_mix.easy' => ['sometimes', 'numeric', 'between:0,1'],
            'difficulty_mix.medium' => ['sometimes', 'numeric', 'between:0,1'],
            'difficulty_mix.hard' => ['sometimes', 'numeric', 'between:0,1'],
        ];
    }
}
