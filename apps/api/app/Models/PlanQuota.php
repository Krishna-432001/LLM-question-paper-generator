<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quotas are data. Changing a limit must never require a deploy — that is the
 * difference between a pricing experiment taking an hour and taking a sprint.
 */
class PlanQuota extends Model
{
    use HasUuidV7;

    public const UNLIMITED = -1;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
            'overage_allowed' => 'boolean',
            'overage_price_inr' => 'decimal:4',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function isUnlimited(): bool
    {
        return $this->limit_value === self::UNLIMITED;
    }

    public function allows(int $used, int $requested): bool
    {
        return $this->isUnlimited()
            || $this->overage_allowed
            || ($used + $requested) <= $this->limit_value;
    }
}
