<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreviousYearQuestion extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'question_number' => 'integer',
            'marks' => 'float',
            'mapping_confidence' => 'float',
        ];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(PreviousYearPaper::class, 'paper_id');
    }

    public function canonicalTopic(): BelongsTo
    {
        return $this->belongsTo(CanonicalTopic::class);
    }

    /**
     * Same normalisation the question bank uses, so a generated question and a
     * real past question that say the same thing produce the same hash and the
     * duplicate check catches it.
     */
    public static function hashFor(string $stem): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($stem))) ?? '';

        return hash('sha256', trim((string) preg_replace('/[^\p{L}\p{N} ]+/u', '', $normalized)));
    }

    /** @return array<string, string|null> */
    public function optionMap(): array
    {
        return [
            'A' => $this->option_a,
            'B' => $this->option_b,
            'C' => $this->option_c,
            'D' => $this->option_d,
        ];
    }
}
