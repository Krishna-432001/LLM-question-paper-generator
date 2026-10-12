<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\AiJob;
use Closure;

/**
 * Re-establishes tenant context inside the worker.
 *
 * A job runs in a fresh process with no request, so the Eloquent global scope
 * and the PostgreSQL RLS session variables would both be unset. Without this,
 * a worker would either see nothing or see everything.
 */
final class TenantAware
{
    public function __construct(private readonly string $aiJobId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $record = TenantContext::withoutTenancy(
            fn () => AiJob::query()->find($this->aiJobId)
        );

        // Platform-owned work (organization_id IS NULL) legitimately runs
        // outside any tenant — content ingestion for the shared bank.
        if ($record === null || $record->organization_id === null) {
            return TenantContext::withoutTenancy(fn () => $next($job));
        }

        $context = new TenantContext(
            organizationId: $record->organization_id,
            userId: $record->user_id,
        );

        app()->instance(TenantContext::class, $context);
        $context->applyToDatabase();

        try {
            return $next($job);
        } finally {
            app()->forgetInstance(TenantContext::class);
            TenantContext::clearDatabase();
        }
    }
}
