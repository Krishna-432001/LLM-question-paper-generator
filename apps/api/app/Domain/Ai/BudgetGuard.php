<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Exceptions\BudgetExceededException;
use App\Models\Organization;
use App\Models\PlanQuota;
use App\Models\UsageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cost control, layer 4: never exceed budget.
 *
 * Checks run BEFORE dispatch, so an over-quota request costs nothing. Failed
 * jobs release their reservation — we never bill a tenant for our own outage.
 */
final class BudgetGuard
{
    public function assertWithinQuota(Organization $organization, string $metric, int $requested = 1): void
    {
        $quota = $this->quotaFor($organization, $metric);

        if ($quota === null || $quota->isUnlimited()) {
            return;
        }

        $used = $this->usedThisPeriod($organization, $metric, $quota->period);

        if (! $quota->allows($used, $requested)) {
            throw new BudgetExceededException(
                "Quota exceeded for {$metric}.",
                metric: $metric,
                limit: $quota->limit_value,
                used: $used,
                resetsAt: $this->periodEnd($quota->period)->toIso8601String(),
            );
        }
    }

    /** Platform-wide circuit breaker: a runaway loop cannot drain the account. */
    public function assertGlobalBreakerClosed(): void
    {
        $cap = (float) config('ai.daily_budget_inr', 0);

        if ($cap <= 0) {
            return;
        }

        $spent = (float) Cache::remember('ai:spend:today', 60, function (): float {
            return (float) DB::table('ai_invocations')
                ->where('created_at', '>=', CarbonImmutable::now()->startOfDay())
                ->sum('cost_inr');
        });

        if ($spent >= $cap) {
            throw new BudgetExceededException(
                'Platform daily AI budget reached; generation is paused.',
                metric: 'ai_spend_inr_daily',
                limit: (int) $cap,
                used: (int) $spent,
                resetsAt: CarbonImmutable::now()->addDay()->startOfDay()->toIso8601String(),
            );
        }
    }

    /** Reserve quota optimistically so two concurrent requests cannot both pass. */
    public function reserve(Organization $organization, string $metric, int $quantity): void
    {
        $key = $this->counterKey($organization, $metric);

        Cache::add($key, 0, $this->periodEnd('month'));
        Cache::increment($key, $quantity);
    }

    public function release(Organization $organization, string $metric, int $quantity): void
    {
        Cache::decrement($this->counterKey($organization, $metric), $quantity);
    }

    /** Commit real usage to the durable ledger once work has actually happened. */
    public function commit(
        Organization $organization,
        string $metric,
        int $quantity,
        float $costInr = 0,
        ?string $aiJobId = null,
        ?string $userId = null,
    ): void {
        UsageRecord::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $userId,
            'metric' => $metric,
            'quantity' => $quantity,
            'cost_inr' => $costInr,
            'ai_job_id' => $aiJobId,
            'occurred_at' => now(),
        ]);
    }

    public function usedThisPeriod(Organization $organization, string $metric, string $period = 'month'): int
    {
        $since = match ($period) {
            'day' => CarbonImmutable::now()->startOfDay(),
            'year' => CarbonImmutable::now()->startOfYear(),
            'lifetime' => CarbonImmutable::createFromTimestamp(0),
            default => CarbonImmutable::now()->startOfMonth(),
        };

        $committed = (int) UsageRecord::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->where('occurred_at', '>=', $since)
            ->sum('quantity');

        $reserved = max(0, (int) Cache::get($this->counterKey($organization, $metric), 0));

        return $committed + $reserved;
    }

    private function quotaFor(Organization $organization, string $metric): ?PlanQuota
    {
        $plan = $organization->activeSubscription?->plan;

        if ($plan === null) {
            return null;
        }

        $plan->loadMissing('quotas');

        return $plan->quotaFor($metric) ?? $plan->quotaFor($metric, 'day');
    }

    private function counterKey(Organization $organization, string $metric): string
    {
        return sprintf(
            'quota:%s:%s:%s',
            $organization->getKey(),
            $metric,
            CarbonImmutable::now()->format('Y-m')
        );
    }

    private function periodEnd(string $period): CarbonImmutable
    {
        return match ($period) {
            'day' => CarbonImmutable::now()->endOfDay(),
            'year' => CarbonImmutable::now()->endOfYear(),
            'lifetime' => CarbonImmutable::now()->addYears(10),
            default => CarbonImmutable::now()->endOfMonth(),
        };
    }
}
