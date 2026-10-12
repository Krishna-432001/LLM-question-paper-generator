<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    public const SHARES_PLATFORM_CONTENT = true;

    /** A claim held longer than this is assumed abandoned. */
    public const CLAIM_MINUTES = 20;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'was_edited' => 'boolean',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'reviewable_id');
    }

    /**
     * Claimable work: never reviewed, and either unclaimed or claimed by
     * someone whose claim has lapsed.
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'in_review'])
            ->where(fn (Builder $q) => $q
                ->whereNull('assigned_to')
                ->orWhere('claim_expires_at', '<', now()));
    }

    public function isClaimedBySomeoneElse(string $userId): bool
    {
        return $this->assigned_to !== null
            && $this->assigned_to !== $userId
            && $this->claim_expires_at?->isFuture() === true;
    }

    /** Did the human agree with the machine's routing? Drives the auto-approval gate. */
    public function reviewerAgreed(): ?bool
    {
        if ($this->decision === null || $this->machine_status === null) {
            return null;
        }

        $machineWouldApprove = $this->machine_status === 'APPROVED';
        $humanApproved = $this->decision === 'approved' && ! $this->was_edited;

        return $machineWouldApprove === $humanApproved;
    }
}
