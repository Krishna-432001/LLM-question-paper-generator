<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_public' => 'boolean',
            'price_inr_monthly' => 'decimal:2',
            'price_inr_yearly' => 'decimal:2',
        ];
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(PlanQuota::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->orderBy('sort_order');
    }

    public function quotaFor(string $metric, string $period = 'month'): ?PlanQuota
    {
        return $this->quotas->firstWhere(
            fn (PlanQuota $q): bool => $q->metric === $metric && $q->period === $period
        );
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) data_get($this->features, $feature, false);
    }
}
