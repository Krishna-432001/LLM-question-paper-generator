<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\AiJob;
use Closure;

/**
 * Cooperative cancellation.
 *
 * A 20-minute OCR job cannot be killed mid-flight safely, so cancellation is a
 * flag checked at job boundaries rather than a signal.
 */
final class SkipIfCancelled
{
    public function __construct(private readonly string $aiJobId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $cancelled = TenantContext::withoutTenancy(
            fn () => AiJob::query()->find($this->aiJobId)?->isCancelled() ?? true
        );

        if ($cancelled) {
            return null;
        }

        return $next($job);
    }
}
