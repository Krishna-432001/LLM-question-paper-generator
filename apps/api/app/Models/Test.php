<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Test extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    /** Every question in one language: tests.language_id. */
    public const MODE_SINGLE = 'single';

    /**
     * Every question in two languages, language_id first and
     * secondary_language_id beneath it, as TNPSC prints its papers.
     */
    public const MODE_BILINGUAL = 'bilingual';

    public const LANGUAGE_MODES = [self::MODE_SINGLE, self::MODE_BILINGUAL];

    protected $guarded = ['id'];

    protected $attributes = [
        'language_mode' => self::MODE_SINGLE,
    ];

    protected function casts(): array
    {
        return [
            'difficulty_mix' => 'array',
            'selection_profile' => 'array',
            'is_shared' => 'boolean',
            'total_marks' => 'float',
            'negative_marking' => 'float',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(TestQuestion::class)->orderBy('sequence');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function secondaryLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'secondary_language_id');
    }

    public function isBilingual(): bool
    {
        return $this->language_mode === self::MODE_BILINGUAL && $this->secondary_language_id !== null;
    }

    /**
     * The languages this paper is shown in, in print order: the primary
     * first, then the second language of a bilingual paper.
     *
     * Every surface that shows the paper — the runner, the solutions, the PDF —
     * reads its languages from here, so a bilingual paper cannot come out
     * bilingual in one place and single-language in another.
     *
     * @return Collection<int, Language>
     */
    public function paperLanguages(): Collection
    {
        $this->loadMissing($this->isBilingual() ? ['language', 'secondaryLanguage'] : ['language']);

        return collect([$this->language, $this->isBilingual() ? $this->secondaryLanguage : null])
            ->filter()
            ->values();
    }
}
