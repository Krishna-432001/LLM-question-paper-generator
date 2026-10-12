<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('questions.approve.platform')
            || $this->user()?->can('questions.approve.org');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject', 'revision'])],
            // A rejection without a reason code cannot feed prompt tuning or
            // the overgeneration auto-tuner, so it is required.
            'reason' => ['required_unless:decision,approve', 'nullable', 'string', 'max:500'],
            'time_spent_seconds' => ['sometimes', 'integer', 'between:1,7200'],
            'comment' => ['sometimes', 'string', 'max:2000'],
            'field_path' => ['sometimes', 'string', 'max:120'],

            'edits' => ['sometimes', 'array'],
            'edits.question_text' => ['sometimes', 'string', 'min:15', 'max:600'],
            'edits.options' => ['sometimes', 'array'],
            'edits.options.*' => ['string', 'min:1', 'max:300'],
            'edits.correct_option' => ['sometimes', Rule::in(['A', 'B', 'C', 'D'])],
            'edits.explanation' => ['sometimes', 'string', 'min:10', 'max:900'],
            'edits.difficulty' => ['sometimes', Rule::in(['easy', 'medium', 'hard'])],
            'edits.canonical_topic_id' => ['sometimes', 'uuid', 'exists:canonical_topics,id'],
        ];
    }
}
