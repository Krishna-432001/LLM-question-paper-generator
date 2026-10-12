<?php

declare(strict_types=1);

namespace App\Http\Requests\Tests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:200'],
            'answers.*.test_question_id' => ['required', 'uuid'],
            // Null is a legitimate answer: it means the student skipped it,
            // which is scored differently from a wrong answer.
            'answers.*.selected_option' => ['present', 'nullable', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'answers.*.time_spent_seconds' => ['sometimes', 'integer', 'between:0,7200'],
        ];
    }
}
