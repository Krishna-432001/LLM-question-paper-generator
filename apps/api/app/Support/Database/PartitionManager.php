<?php

declare(strict_types=1);

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates and retires monthly RANGE partitions.
 *
 * Partitioning at 10M rows is a maintenance window; at 500M it is an outage.
 * So the high-volume append-only tables (usage_records, ai_invocations,
 * audit_logs, test_answers) are born partitioned and a scheduled job keeps a
 * rolling window of future partitions open.
 */
final class PartitionManager
{
    /** Tables that must always have future partitions available. */
    public const MONTHLY_TABLES = [
        'usage_records' => 'occurred_at',
        'ai_invocations' => 'created_at',
        'audit_logs' => 'created_at',
    ];

    /**
     * Ensure partitions exist for [now + $fromOffset months, now + $toOffset months].
     */
    public static function ensureMonthlyRange(string $table, int $fromOffset, int $toOffset): void
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        for ($i = $fromOffset; $i <= $toOffset; $i++) {
            self::ensureMonthlyPartition($table, $month->addMonths($i));
        }
    }

    public static function ensureMonthlyPartition(string $table, CarbonImmutable $month): string
    {
        $start = $month->startOfMonth();
        $end = $start->addMonth();
        $name = sprintf('%s_p%s', $table, $start->format('Y_m'));

        DB::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM (%s) TO (%s)',
            $name,
            $table,
            "'{$start->toDateTimeString()}+00'",
            "'{$end->toDateTimeString()}+00'",
        ));

        return $name;
    }

    /**
     * Detach (but never drop) partitions older than $keepMonths. Detached
     * partitions are archived to object storage by the maintenance job before
     * any destructive action is taken.
     *
     * @return list<string> detached partition names
     */
    public static function detachOlderThan(string $table, int $keepMonths): array
    {
        $cutoff = CarbonImmutable::now('UTC')->startOfMonth()->subMonths($keepMonths);
        $detached = [];

        /** @var list<object{child: string}> $partitions */
        $partitions = DB::select(<<<'SQL'
            SELECT c.relname AS child
            FROM pg_inherits i
            JOIN pg_class p ON p.oid = i.inhparent
            JOIN pg_class c ON c.oid = i.inhrelid
            WHERE p.relname = ?
            ORDER BY c.relname
        SQL, [$table]);

        foreach ($partitions as $partition) {
            if (! preg_match('/_p(\d{4})_(\d{2})$/', $partition->child, $m)) {
                continue;
            }

            $partitionMonth = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');

            if ($partitionMonth !== null && $partitionMonth->lt($cutoff)) {
                DB::statement("ALTER TABLE {$table} DETACH PARTITION {$partition->child}");
                $detached[] = $partition->child;
            }
        }

        return $detached;
    }
}
