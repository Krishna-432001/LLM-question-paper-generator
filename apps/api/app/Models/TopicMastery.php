<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Exponentially weighted accuracy per (student, topic). Recent performance
 * matters more than an average over the whole preparation, which is what lets
 * the plan respond to improvement instead of averaging it away.
 */
class TopicMastery extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $table = 'topic_mastery';

    protected $guarded = ['id'];

    /** Smoothing factor: how much one test moves the estimate. */
    public const ALPHA = 0.4;

    protected function casts(): array
    {
        return [
            'mastery' => 'float',
            'last_practiced_at' => 'datetime',
            'next_review_at' => 'datetime',
        ];
    }

    public function updateWith(int $correct, int $attempted): void
    {
        if ($attempted <= 0) {
            return;
        }

        $observed = $correct / $attempted;
        $mastery = round((self::ALPHA * $observed) + ((1 - self::ALPHA) * (float) $this->mastery), 4);

        $this->forceFill([
            'attempts' => $this->attempts + $attempted,
            'correct' => $this->correct + $correct,
            'mastery' => $mastery,
            'last_practiced_at' => now(),
            // Spaced repetition: a shaky topic comes back tomorrow, a mastered
            // one in about two weeks. Squared so the interval only stretches
            // once mastery is genuinely high.
            'next_review_at' => now()->addDays(max(1, (int) round(1 + 13 * $mastery ** 2))),
        ])->save();
    }
}
