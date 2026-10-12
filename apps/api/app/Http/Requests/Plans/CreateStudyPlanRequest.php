<?php

declare(strict_types=1);

namespace App\Http\Requests\Plans;

use Illuminate\Foundation\Http\FormRequest;

final class CreateStudyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plans.create.own')
            || $this->user()?->can('plans.create.org');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'exam_id' => ['required', 'uuid', 'exists:exams,id'],
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'start_date' => ['sometimes', 'date', 'after_or_equal:today'],
            // The exam date bounds a plan that runs to the exam rather than a
            // fixed horizon; 5 years out is a generous sanity ceiling.
            'exam_date' => ['sometimes', 'date', 'after:start_date', 'before:+5 years'],
            'hours_per_day' => ['sometimes', 'numeric', 'between:0.5,16'],
            'off_days' => ['sometimes', 'array', 'max:6'],
            'off_days.*' => ['integer', 'between:0,6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'off_days.max' => 'At least one day a week has to be a study day.',
            'hours_per_day.between' => 'Study hours must be between 0.5 and 16 per day.',
        ];
    }
}
