<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * Writes the record of who did what.
 *
 * The table has been partitioned since the schema was written and had never
 * held a row: an audit trail nothing writes to is worse than none, because it
 * looks like a record exists. (Its append-only claim was also only a comment
 * until a trigger was added to make it true.)
 *
 * Two decisions shape this class.
 *
 * First, an audit entry is written inside the caller's transaction, not after
 * it. Best-effort auditing loses exactly the records that matter most — the
 * ones written while something was going wrong. If the change commits, its
 * record commits with it; if the record cannot be written, the change is not
 * safe to keep either.
 *
 * Second, only what actually differed is stored. Snapshotting whole rows makes
 * every entry expensive and buries the one field that changed among forty that
 * did not.
 */
final class AuditRecorder
{
    /**
     * Values that must never be written to a log, whatever a caller passes.
     */
    private const REDACTED = [
        'password', 'password_confirmation', 'remember_token',
        'api_key', 'token', 'secret',
    ];

    /**
     * @param  array<string, mixed>  $changes  new values, or ['old' => …, 'new' => …]
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $actorId = null,
    ): AuditLog {
        $context = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;

        return AuditLog::query()->create([
            'organization_id' => $context?->organizationId,
            'actor_user_id' => $actorId ?? $context?->userId,
            'actor_role' => $context?->isPlatformAdmin ? 'platform_admin' : 'member',
            'action' => $action,
            'auditable_type' => $subject !== null ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'changes' => $this->redact($changes),
            'ip' => $this->ip(),
            'user_agent' => mb_substr((string) Request::userAgent(), 0, 500) ?: null,
            // Ties an entry to the request it came from, so one admin action
            // that touched twenty rows can be reassembled from the log.
            'request_id' => Request::header('X-Request-Id') ?: request()?->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }

    /**
     * Records a change to a model, storing only the fields that moved.
     *
     * @param  array<string, mixed>  $before
     */
    public function recordChange(string $action, Model $subject, array $before): ?AuditLog
    {
        $after = $subject->getAttributes();
        $diff = [];

        foreach ($after as $field => $value) {
            // updated_at moves on every write by definition. Recording it
            // means every entry has a change even when nothing did.
            if (in_array($field, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $old = $before[$field] ?? null;

            // Loose comparison on purpose: a value read back from Postgres is
            // often a string where the written one was an int, and recording
            // 1 => "1" as a change would fill the log with noise.
            if ($old != $value) {
                $diff[$field] = ['old' => $old, 'new' => $value];
            }
        }

        // Nothing moved. An entry saying so would only make the real ones
        // harder to find.
        if ($diff === []) {
            return null;
        }

        return $this->record($action, $subject, $diff);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function redact(array $changes): array
    {
        foreach ($changes as $field => $value) {
            foreach (self::REDACTED as $secret) {
                if (str_contains(mb_strtolower((string) $field), $secret)) {
                    $changes[$field] = '[redacted]';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $changes[$field] = $this->redact($value);
            }
        }

        return $changes;
    }

    private function ip(): ?string
    {
        $ip = Request::ip();

        // Console and queue work has no client address, and storing the
        // worker's own is a lie about where the action came from.
        return $ip === null || $ip === '' ? null : $ip;
    }
}
