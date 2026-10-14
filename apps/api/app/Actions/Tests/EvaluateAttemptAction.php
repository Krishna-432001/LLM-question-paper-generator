<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Models\Question;
use App\Models\TestAttempt;
use App\Models\TestResult;
use App\Models\TopicMastery;
use Illuminate\Support\Facades\DB;

/**
 * Scores a submitted attempt and feeds the adaptive engine.
 *
 * The answer key is read from question_options — never from a translation —
 * so a student sitting the Tamil paper is marked against exactly the same
 * truth as one sitting the English paper.
 *
 * A question with no correct option at all (an uploaded past paper whose key
 * was never printed) is unscored: answered or not, it counts as neither right
 * nor wrong. Marking it wrong would turn "we do not know the answer" into
 * "you got it wrong", which is a false statement about the student.
 */
final class EvaluateAttemptAction
{
    /** Below this a topic is weak enough to warrant extra revision. */
    private const WEAK_THRESHOLD = 0.55;

    /** Above this, with enough evidence, the plan can stop drilling it. */
    private const STRONG_THRESHOLD = 0.85;

    /**
     * @param  list<array{test_question_id: string, selected_option: string|null, time_spent_seconds?: int}>  $answers
     */
    public function execute(TestAttempt $attempt, array $answers): TestResult
    {
        $test = $attempt->test()->with('questions.question.options')->first();
        $byId = $test->questions->keyBy('id');

        return DB::transaction(function () use ($attempt, $test, $byId, $answers): TestResult {
            $correct = 0;
            $wrong = 0;
            $timeTaken = 0;
            $byTopic = [];
            $byDifficulty = [];
            $unscored = $test->questions
                ->filter(static fn ($testQuestion): bool => $testQuestion->question->correctOptionKey() === null)
                ->count();

            foreach ($answers as $answer) {
                $testQuestion = $byId->get($answer['test_question_id']);

                if ($testQuestion === null) {
                    continue;   // an answer for a question not on this paper
                }

                /** @var Question $question */
                $question = $testQuestion->question;
                $selected = $answer['selected_option'] ?? null;
                $key = $question->correctOptionKey();
                $isCorrect = $selected !== null && $selected === $key;
                $timeTaken += (int) ($answer['time_spent_seconds'] ?? 0);

                // The choice is still recorded, with is_correct left null, so
                // the student's answer survives for when a key is added.
                DB::table('test_answers')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid7(),
                    'test_attempt_id' => $attempt->getKey(),
                    'test_question_id' => $testQuestion->getKey(),
                    'question_id' => $question->getKey(),
                    'selected_option' => $selected,
                    'is_correct' => $selected === null || $key === null ? null : $isCorrect,
                    'time_spent_seconds' => (int) ($answer['time_spent_seconds'] ?? 0),
                    'answered_at' => now(),
                ]);

                if ($key === null) {
                    continue;   // unscored: no evidence for the score, the topic or the difficulty
                }

                $isCorrect ? $correct++ : ($selected !== null ? $wrong++ : null);

                $topic = $question->canonical_topic_id;

                if ($topic !== null) {
                    $byTopic[$topic] ??= ['attempted' => 0, 'correct' => 0];
                    $byTopic[$topic]['attempted'] += $selected !== null ? 1 : 0;
                    $byTopic[$topic]['correct'] += $isCorrect ? 1 : 0;
                }

                $byDifficulty[$question->difficulty] ??= ['attempted' => 0, 'correct' => 0];
                $byDifficulty[$question->difficulty]['attempted'] += $selected !== null ? 1 : 0;
                $byDifficulty[$question->difficulty]['correct'] += $isCorrect ? 1 : 0;
            }

            $total = $test->questions->count();
            $attempted = $correct + $wrong;
            // Left blank is counted only among questions that could be marked.
            $scorable = $total - $unscored;
            $marks = (float) ($test->questions->first()->marks ?? 1);
            $rawScore = $correct * $marks;
            $negative = $wrong * (float) $test->negative_marking;

            $result = TestResult::query()->create([
                'test_attempt_id' => $attempt->getKey(),
                'total_questions' => $total,
                'attempted' => $attempted,
                'correct' => $correct,
                'wrong' => $wrong,
                'unattempted' => max(0, $scorable - $attempted),
                'unscored' => $unscored,
                'raw_score' => $rawScore,
                'negative_score' => $negative,
                'final_score' => $rawScore - $negative,
                // Nothing could be marked (no key for anything answered): an
                // accuracy of 0% would claim every answer was wrong.
                'accuracy' => $attempted > 0
                    ? round($correct / $attempted * 100, 2)
                    : ($unscored > 0 ? null : 0),
                'time_taken_seconds' => $timeTaken,
                'topic_breakdown' => $this->withAccuracy($byTopic),
                'difficulty_breakdown' => $this->withAccuracy($byDifficulty),
                'weak_topics' => $this->topicsBelow($byTopic, self::WEAK_THRESHOLD),
                'strong_topics' => $this->topicsAbove($byTopic, self::STRONG_THRESHOLD),
            ]);

            $attempt->forceFill(['submitted_at' => now(), 'status' => 'submitted'])->save();

            $this->updateMastery($attempt, $test->exam_id, $byTopic);

            return $result;
        });
    }

    /**
     * Feeds the adaptive engine. Poor performance raises a topic's weight in
     * the next plan and the next test; strong performance lets the schedule
     * move on instead of drilling what the student already knows.
     *
     * @param  array<string, array{attempted: int, correct: int}>  $byTopic
     */
    private function updateMastery(TestAttempt $attempt, string $examId, array $byTopic): void
    {
        foreach ($byTopic as $topicId => $counts) {
            if ($counts['attempted'] === 0) {
                continue;
            }

            $mastery = TopicMastery::query()->firstOrCreate(
                [
                    'user_id' => $attempt->user_id,
                    'exam_id' => $examId,
                    'canonical_topic_id' => $topicId,
                ],
                ['mastery' => 0.5],
            );

            $mastery->updateWith($counts['correct'], $counts['attempted']);
        }
    }

    /**
     * @param  array<string, array{attempted: int, correct: int}>  $buckets
     * @return array<string, array<string, float|int>>
     */
    private function withAccuracy(array $buckets): array
    {
        return array_map(
            static fn (array $b): array => $b + [
                'accuracy' => $b['attempted'] > 0
                    ? round($b['correct'] / $b['attempted'] * 100, 2)
                    : 0.0,
            ],
            $buckets,
        );
    }

    /**
     * @param  array<string, array{attempted: int, correct: int}>  $byTopic
     * @return list<string>
     */
    private function topicsBelow(array $byTopic, float $threshold): array
    {
        return array_values(array_keys(array_filter(
            $byTopic,
            static fn (array $b): bool => $b['attempted'] > 0
                && ($b['correct'] / $b['attempted']) < $threshold,
        )));
    }

    /**
     * @param  array<string, array{attempted: int, correct: int}>  $byTopic
     * @return list<string>
     */
    private function topicsAbove(array $byTopic, float $threshold): array
    {
        return array_values(array_keys(array_filter(
            $byTopic,
            static fn (array $b): bool => $b['attempted'] > 0
                && ($b['correct'] / $b['attempted']) >= $threshold,
        )));
    }
}
