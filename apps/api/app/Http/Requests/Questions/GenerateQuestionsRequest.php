<?php

declare(strict_types=1);

namespace App\Http\Requests\Questions;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('questions.generate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'language_id' => ['required', 'uuid', 'exists:languages,id'],
            'exam_id' => ['sometimes', 'uuid', 'exists:exams,id'],
            // Capped per request: the overgeneration factor multiplies this by
            // ~1.7, and an unbounded target is an unbounded token bill.
            'target_count' => ['required', 'integer', 'between:5,500'],
            // Which shapes of question to produce. All of them are four options
            // with one correct answer, so the whole downstream pipeline -
            // options, answer key, PDF - is unaffected by the choice.
            // Narrow the target to one cell of the bank, so a thin topic can
            // be topped up without re-counting the whole exam.
            'canonical_topic_id' => ['sometimes', 'nullable', 'uuid', 'exists:canonical_topics,id'],
            'difficulty' => ['sometimes', 'nullable', 'in:easy,medium,hard'],
            'count_pending' => ['sometimes', 'boolean'],
            'formats' => ['sometimes', 'array', 'min:1'],
            'formats.*' => ['in:mcq4,statement_based,assertion_reason,match_the_following,true_false'],
            'book_lesson_ids' => ['sometimes', 'array', 'max:50'],
            'book_lesson_ids.*' => ['uuid', 'exists:book_lessons,id'],
        ];
    }
}
