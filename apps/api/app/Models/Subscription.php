<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function isUsable(): bool
    {
        // past_due still reads: a failed payment must not delete a student's
        // study plan the night before an exam. Only new AI spend is blocked.
        return in_array($this->status, ['trialing', 'active', 'past_due'], true)
            && $this->current_period_end->isFuture();
    }

    public function allowsNewGeneration(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true);
    }
}
