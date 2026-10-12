<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Overgeneration factor and quality thresholds, per exam/subject.
 *
 * These are auto-tuned nightly from observed acceptance rates: a topic
 * accepting 55% of candidates earns a higher factor, a mature topic at 90%
 * drops to 1.45 and saves money. Never a code constant.
 */
class GenerationPolicy extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'overgeneration_factor' => 'float',
            'auto_approve_threshold' => 'float',
            'review_threshold' => 'float',
            'allow_auto_approve' => 'boolean',
            'dup_hard_threshold' => 'float',
            'dup_grey_threshold' => 'float',
            'difficulty_mix' => 'array',
        ];
    }

    /** Most specific policy wins: subject → exam → global default. */
    public static function resolve(?string $examId = null, ?string $examSubjectId = null): self
    {
        $policy = static::query()
            ->when($examSubjectId, fn ($q) => $q->orWhere('exam_subject_id', $examSubjectId))
            ->when($examId, fn ($q) => $q->orWhere('exam_id', $examId))
            ->orderByRaw('exam_subject_id IS NULL, exam_id IS NULL')
            ->first();

        return $policy ?? new self([
            'overgeneration_factor' => 1.70,
            'batch_size' => 25,
            'auto_approve_threshold' => 85,
            'review_threshold' => 60,
            'allow_auto_approve' => false,
            'dup_hard_threshold' => 0.93,
            'dup_grey_threshold' => 0.86,
            'difficulty_mix' => ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2],
        ]);
    }

    /** Ask for 1.7× the target, never for an exact count. */
    public function rawTargetFor(int $approvedTarget): int
    {
        return (int) ceil($approvedTarget * $this->overgeneration_factor);
    }
}
