<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's counters answer "where are we"; this answers "which way is
 * it going", which is the question that actually changes what someone does.
 *
 * One rule shapes the whole class: **days with no activity must appear as
 * zero**. Grouping by date alone returns only the days something happened, and
 * a chart drawn from that silently compresses a fortnight of nothing into a
 * flat line next to a busy day — it reads as steady output when the truth is a
 * stall. Every series is therefore joined against a generated calendar.
 */
final class TrendController extends Controller
{
    /** Long enough to see a pattern, short enough that the queries stay cheap. */
    private const MAX_DAYS = 90;

    private const DEFAULT_DAYS = 30;

    public function index(Request $request): JsonResponse
    {
        $days = min(self::MAX_DAYS, max(7, $request->integer('days', self::DEFAULT_DAYS)));

        $series = [
            'drafted' => $this->series($days, 'questions', 'created_at'),
            // A different set of questions from the ones drafted that day:
            // approval happens later, and conflating the two makes review
            // throughput invisible.
            'approved' => $this->series($days, 'questions', 'approved_at'),
            'documents' => $this->series($days, 'generated_documents', 'created_at'),
            'spend_inr' => $this->series($days, 'ai_invocations', 'created_at', 'cost_inr'),
        ];

        // The boundaries are read off the series rather than recomputed in PHP.
        // The calendar is built by the database, and if the two clocks sit in
        // different timezones a separately computed header would contradict
        // the data it labels.
        $dates = array_column($series['drafted'], 'date');

        return response()->json([
            'data' => [
                'days' => $days,
                'from' => $dates[0] ?? null,
                'to' => end($dates) ?: null,
                'series' => $series,
            ],
        ]);
    }

    /**
     * One row per day in the window, zero-filled.
     *
     * @return list<array{date: string, value: float}>
     */
    private function series(int $days, string $table, string $column, ?string $sum = null): array
    {
        // Aggregate then LEFT JOIN onto the calendar, rather than the other way
        // round: joining the calendar to the raw table would scan every row per
        // day, and the shape of the result would still depend on the data.
        $measure = $sum !== null ? "coalesce(sum(t.{$sum}), 0)" : 'count(*)';

        $rows = DB::select(<<<SQL
            WITH calendar AS (
                SELECT generate_series(
                    (current_date - (? - 1) * interval '1 day')::date,
                    current_date,
                    interval '1 day'
                )::date AS day
            ),
            totals AS (
                SELECT t.{$column}::date AS day, {$measure} AS value
                  FROM {$table} t
                 WHERE t.{$column} >= current_date - (? - 1) * interval '1 day'
                   AND t.{$column} IS NOT NULL
                 GROUP BY 1
            )
            SELECT c.day::text AS date, coalesce(totals.value, 0) AS value
              FROM calendar c
              LEFT JOIN totals ON totals.day = c.day
             ORDER BY c.day
        SQL, [$days, $days]);

        return array_map(
            static fn (object $row): array => [
                'date' => $row->date,
                'value' => round((float) $row->value, 2),
            ],
            $rows,
        );
    }
}
