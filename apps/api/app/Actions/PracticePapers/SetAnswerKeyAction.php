<?php

declare(strict_types=1);

namespace App\Actions\PracticePapers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\PracticePapers\Exceptions\PracticePaperException;
use App\Domain\PracticePapers\PracticePaper;
use App\Domain\Pyq\PyqParser;
use App\Models\QuestionOption;
use App\Models\Test;
use Illuminate\Support\Facades\DB;

/**
 * The answer key for a paper that was uploaded without one, or with a wrong
 * one.
 *
 * Entries are matched by the number printed in the paper, not by position in
 * the test, because that is the number the key was printed against. A key
 * changes which option is correct on the paper's own questions only — never
 * on a bank question, whatever a test happens to contain.
 *
 * Attempts already submitted are not re-scored. A result is a record of what
 * was marked at the time; quietly rewriting it would make a score the student
 * saw yesterday disagree with the one shown today.
 */
final class SetAnswerKeyAction
{
    public function __construct(
        private readonly PyqParser $parser,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  string|array<int|string, mixed>  $key  "1-A 2-C 3-B" or {"1": "A", "2": "C"}
     * @return array<string, mixed>
     */
    public function execute(Test $test, string|array $key): array
    {
        $answers = is_string($key) ? $this->parser->readAnswerKey($key) : $this->fromMap($key);

        if ($answers === []) {
            throw PracticePaperException::unreadableKey();
        }

        $numbers = PracticePaper::questionNumbers($test);
        $byNumber = [];

        foreach ($test->questions()->with('question.options')->get() as $testQuestion) {
            $byNumber[$numbers[$testQuestion->sequence] ?? $testQuestion->sequence] = $testQuestion->question;
        }

        $updated = [];
        $unchanged = 0;
        $unmatched = [];

        DB::transaction(function () use ($answers, $byNumber, &$updated, &$unchanged, &$unmatched, $test): void {
            foreach ($answers as $number => $option) {
                $question = $byNumber[$number] ?? null;

                if ($question === null || $question->origin !== 'previous_paper') {
                    $unmatched[] = $number;

                    continue;
                }

                if ($question->correctOptionKey() === $option) {
                    $unchanged++;

                    continue;
                }

                // Cleared then set inside one transaction: the one-correct-
                // option trigger is deferred to commit, so it only ever sees
                // the finished state.
                QuestionOption::query()->where('question_id', $question->getKey())->update(['is_correct' => false]);
                QuestionOption::query()
                    ->where('question_id', $question->getKey())
                    ->where('option_key', $option)
                    ->update(['is_correct' => true]);

                $updated[] = $number;
            }

            $this->audit->record('practice_paper.answer_key_set', $test, [
                'entries' => count($answers),
                'updated' => $updated,
                'unchanged' => $unchanged,
                'unmatched' => $unmatched,
            ]);
        });

        $keyed = DB::table('test_questions as tq')
            ->join('question_options as o', 'o.question_id', '=', 'tq.question_id')
            ->where('tq.test_id', $test->getKey())
            ->where('o.is_correct', true)
            ->count();

        return [
            'test_id' => $test->getKey(),
            'updated' => count($updated),
            'unchanged' => $unchanged,
            'unmatched' => $unmatched,
            'keyed' => $keyed,
            'question_count' => $test->question_count,
            'rescored' => false,
            'message' => 'Answer key saved. Earlier attempts are not re-scored: they keep the marks they were '
                .'given. Start a new attempt to be scored against this key.',
        ];
    }

    /**
     * A map of question number to option, or a plain list read as questions
     * 1, 2, 3… in order.
     *
     * @param  array<int|string, mixed>  $map
     * @return array<int, string>
     */
    private function fromMap(array $map): array
    {
        if (array_is_list($map)) {
            $map = array_combine(range(1, max(1, count($map))), $map ?: [null]);
        }

        $answers = [];

        foreach ($map as $number => $option) {
            $key = is_string($option) ? $this->parser->optionKey($option) : null;

            if ($key !== null && is_numeric($number) && (int) $number > 0) {
                $answers[(int) $number] = $key;
            }
        }

        ksort($answers);

        return $answers;
    }
}
