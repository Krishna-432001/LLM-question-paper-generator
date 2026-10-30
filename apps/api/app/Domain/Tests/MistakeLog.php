<?php

declare(strict_types=1);

namespace App\Domain\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The student's mistakes, question by question, read from their marked answers.
 *
 * A question joins the log the first time it is answered wrong. It comes back
 * for a retest 1, 3 and 7 days after its last answer, and leaves once it has
 * been answered right three times in a row since its last wrong answer. A new
 * wrong answer starts the ladder again. Nothing is stored: the log is always
 * what test_answers says, so it cannot drift from the student's real record.
 */
final class MistakeLog
{
    /** Days until the next retest, by right answers in a row since the last wrong one. */
    public const LADDER = [1, 3, 7];

    /**
     * @return list<array{question_id: string, canonical_topic_id: ?string, wrong_count: int, streak: int, last_answered_at: CarbonImmutable, due_at: CarbonImmutable}>
     */
    public function open(string $userId, string $examId): array
    {
        $rows = DB::table('test_answers as a')
            ->join('test_attempts as at', 'at.id', '=', 'a.test_attempt_id')
            ->join('tests as t', 't.id', '=', 'at.test_id')
            ->join('questions as q', 'q.id', '=', 'a.question_id')
            ->where('at.user_id', $userId)
            ->where('t.exam_id', $examId)
            ->whereNotNull('a.is_correct')
            ->whereIn('a.question_id', fn ($q) => $q
                ->select('a2.question_id')
                ->from('test_answers as a2')
                ->join('test_attempts as at2', 'at2.id', '=', 'a2.test_attempt_id')
                ->where('at2.user_id', $userId)
                ->where('a2.is_correct', false))
            ->orderBy('a.question_id')
            ->orderBy('a.answered_at')
            ->get(['a.question_id', 'a.is_correct', 'a.answered_at', 'q.canonical_topic_id', 'q.status']);

        $open = [];

        foreach ($rows->groupBy('question_id') as $questionId => $answers) {
            // A question retired or rejected since cannot be set again.
            if ($answers->first()->status !== 'APPROVED') {
                continue;
            }

            $wrong = 0;
            $streak = 0;

            foreach ($answers as $answer) {
                if ($answer->is_correct) {
                    $streak++;
                } else {
                    $wrong++;
                    $streak = 0;
                }
            }

            if ($streak >= count(self::LADDER)) {
                continue;   // recovered
            }

            $last = CarbonImmutable::parse($answers->last()->answered_at);
            $open[] = [
                'question_id' => (string) $questionId,
                'canonical_topic_id' => $answers->first()->canonical_topic_id,
                'wrong_count' => $wrong,
                'streak' => $streak,
                'last_answered_at' => $last,
                'due_at' => $last->addDays(self::LADDER[$streak]),
            ];
        }

        usort($open, static fn (array $a, array $b): int => $a['due_at'] <=> $b['due_at']);

        return $open;
    }

    /**
     * Counts for the dashboard: open mistakes, how many are due now, and the
     * repeated-error rate — of the questions ever answered wrong, the share
     * answered wrong more than once.
     *
     * @return array{open: int, due: int, ever_wrong: int, repeated: int, repeated_rate: ?float}
     */
    public function summary(string $userId, string $examId): array
    {
        $open = $this->open($userId, $examId);
        $now = CarbonImmutable::now();

        $wrongCounts = DB::table('test_answers as a')
            ->join('test_attempts as at', 'at.id', '=', 'a.test_attempt_id')
            ->join('tests as t', 't.id', '=', 'at.test_id')
            ->where('at.user_id', $userId)
            ->where('t.exam_id', $examId)
            ->where('a.is_correct', false)
            ->groupBy('a.question_id')
            ->selectRaw('count(*) AS n')
            ->pluck('n');

        $everWrong = $wrongCounts->count();
        $repeated = $wrongCounts->filter(static fn ($n): bool => (int) $n > 1)->count();

        return [
            'open' => count($open),
            'due' => count(array_filter($open, static fn (array $m): bool => $m['due_at'] <= $now)),
            'ever_wrong' => $everWrong,
            'repeated' => $repeated,
            'repeated_rate' => $everWrong > 0 ? round($repeated / $everWrong * 100, 1) : null,
        ];
    }
}
