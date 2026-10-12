<?php

declare(strict_types=1);

namespace App\Domain\Plans;

use App\Domain\Plans\DTOs\PlanRequest;
use App\Domain\Plans\DTOs\ScheduledDay;
use App\Models\ExamTopic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A deterministic constraint-satisfaction scheduler — deliberately not an LLM.
 *
 *   cost per plan          ₹0 vs ₹4–12
 *   reproducible           yes vs no
 *   hours/day arithmetic   exact vs frequently wrong
 *   full-syllabus coverage provable vs hoped for
 *
 * A model contributes at most a short coaching note per day type, generated
 * once and shared across every student, so its cost amortises to nothing.
 */
final class PlanScheduler
{
    /**
     * Nobody studies productively for every scheduled minute. Planning at 100%
     * of stated hours produces a plan that is abandoned in week one.
     */
    private const FOCUS_FACTOR = 0.85;

    /** Spaced repetition ladder, in days after first study. */
    private const REPETITION_LADDER = [1, 3, 7, 21];

    /** Share of the timeline reserved for full-syllabus revision at the end. */
    private const REVISION_TAIL = 0.12;

    /**
     * @return array{
     *   days: list<ScheduledDay>,
     *   dropped: list<array<string, mixed>>,
     *   coverage_percent: float
     * }
     */
    public function schedule(PlanRequest $request): array
    {
        $studyDates = $this->studyDates($request);

        if ($studyDates === []) {
            return ['days' => [], 'dropped' => [], 'coverage_percent' => 0.0];
        }

        $topics = $this->prioritise($request);
        $capacity = $this->capacityMinutes($studyDates, $request->hoursPerDay);

        [$scheduled, $dropped] = $this->fitToCapacity($topics, $capacity, $request);

        $days = $this->binPack($scheduled, $studyDates, $request);
        $days = $this->injectRepetition($days, $request);
        $days = $this->injectAssessments($days, $request);

        $coverage = $topics->isEmpty()
            ? 100.0
            : round(count($scheduled) / $topics->count() * 100, 2);

        return ['days' => $days, 'dropped' => $dropped, 'coverage_percent' => $coverage];
    }

    /**
     * Topic priority = syllabus weight × exam frequency × the student's own
     * weakness. A topic the student is already strong at is scheduled, but
     * later and for less time.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function prioritise(PlanRequest $request): Collection
    {
        return ExamTopic::query()
            ->where('syllabus_version_id', $request->syllabusVersionId)
            ->leaves()
            ->with('examUnit')
            ->get()
            ->map(function (ExamTopic $topic) use ($request): array {
                $mastery = $request->masteryByTopic[$topic->canonical_topic_id] ?? 0.5;
                $prediction = $request->predictionByTopic[$topic->canonical_topic_id] ?? 0.5;
                $weakness = 1 - $mastery;

                return [
                    'exam_topic_id' => $topic->getKey(),
                    'canonical_topic_id' => $topic->canonical_topic_id,
                    'name' => $topic->name,
                    'unit_id' => $topic->exam_unit_id,
                    // Weak topics get more minutes, not just earlier slots.
                    'minutes' => (int) ceil($topic->estimated_minutes * (1 + $weakness * 0.5)),
                    'priority' => round(
                        ((float) $topic->weight * 0.4) + ($prediction * 0.35) + ($weakness * 0.25),
                        6,
                    ),
                ];
            })
            ->sortByDesc('priority')
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $topics
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function fitToCapacity(Collection $topics, int $capacity, PlanRequest $request): array
    {
        // The tail is held back for revision, not new material.
        $available = (int) floor($capacity * (1 - self::REVISION_TAIL));

        $scheduled = [];
        $dropped = [];
        $used = 0;

        foreach ($topics as $topic) {
            if ($used + $topic['minutes'] <= $available) {
                $scheduled[] = $topic;
                $used += $topic['minutes'];

                continue;
            }

            // Capacity exhausted. The student is told exactly what was left
            // out and why — never silently given a partial syllabus.
            $dropped[] = [
                'topic' => $topic['name'],
                'exam_topic_id' => $topic['exam_topic_id'],
                'reason' => 'insufficient time before the exam date',
            ];
        }

        return [$this->interleaveSubjects($scheduled), $dropped];
    }

    /**
     * Five consecutive history days is how a plan gets abandoned. Round-robin
     * across units keeps variety without losing priority order.
     *
     * @param  list<array<string, mixed>>  $topics
     * @return list<array<string, mixed>>
     */
    private function interleaveSubjects(array $topics): array
    {
        $byUnit = [];

        foreach ($topics as $topic) {
            $byUnit[$topic['unit_id']][] = $topic;
        }

        $interleaved = [];

        while ($byUnit !== []) {
            foreach (array_keys($byUnit) as $unit) {
                $interleaved[] = array_shift($byUnit[$unit]);

                if ($byUnit[$unit] === []) {
                    unset($byUnit[$unit]);
                }
            }
        }

        return $interleaved;
    }

    /**
     * @param  list<array<string, mixed>>  $topics
     * @param  list<CarbonImmutable>  $dates
     * @return list<ScheduledDay>
     */
    private function binPack(array $topics, array $dates, PlanRequest $request): array
    {
        $perDay = (int) floor($request->hoursPerDay * 60 * self::FOCUS_FACTOR);
        $days = [];
        $cursor = 0;

        foreach ($dates as $index => $date) {
            $items = [];
            $minutes = 0;

            while ($cursor < count($topics)) {
                $topic = $topics[$cursor];

                // Always place at least one topic, even if it overflows the
                // day: an oversized topic must not stall the whole schedule.
                if ($items !== [] && $minutes + $topic['minutes'] > $perDay) {
                    break;
                }

                $items[] = [
                    'type' => 'study',
                    'exam_topic_id' => $topic['exam_topic_id'],
                    'canonical_topic_id' => $topic['canonical_topic_id'],
                    'name' => $topic['name'],
                    'minutes' => $topic['minutes'],
                ];

                $minutes += $topic['minutes'];
                $cursor++;
            }

            $days[] = new ScheduledDay(
                dayNumber: $index + 1,
                date: $date,
                role: $items === [] ? 'revision' : 'new',
                targetMinutes: $minutes,
                items: $items,
            );
        }

        return $days;
    }

    /**
     * Spaced repetition on a fixed ladder rather than per-item SM-2: a plan
     * has to be printable and predictable a month in advance.
     *
     * @param  list<ScheduledDay>  $days
     * @return list<ScheduledDay>
     */
    private function injectRepetition(array $days, PlanRequest $request): array
    {
        $byNumber = [];

        foreach ($days as $day) {
            $byNumber[$day->dayNumber] = $day;
        }

        foreach ($days as $day) {
            foreach ($day->items as $item) {
                if ($item['type'] !== 'study') {
                    continue;
                }

                foreach (self::REPETITION_LADDER as $offset) {
                    $target = $byNumber[$day->dayNumber + $offset] ?? null;

                    if ($target === null) {
                        continue;
                    }

                    $target->items[] = [
                        'type' => 'revise',
                        'exam_topic_id' => $item['exam_topic_id'],
                        'canonical_topic_id' => $item['canonical_topic_id'],
                        'name' => $item['name'],
                        // Revision is a fraction of first-pass time.
                        'minutes' => max(5, (int) round($item['minutes'] * 0.25)),
                        'repeat_of' => $day->dayNumber,
                    ];

                    if ($target->role === 'new') {
                        $target->role = 'new_plus_revision';
                    }
                }
            }
        }

        return $days;
    }

    /**
     * Assessment placement is what distinguishes the five modes.
     *
     * @param  list<ScheduledDay>  $days
     * @return list<ScheduledDay>
     */
    private function injectAssessments(array $days, PlanRequest $request): array
    {
        $config = $request->testConfig;

        $cycle = (int) ($config['cycle_days'] ?? 0);

        foreach ($days as $day) {
            $isCycleDay = $cycle > 0 && $day->dayNumber % $cycle === 0;

            if ($isCycleDay) {
                // A cycle-test day carries the cycle test only. Stacking the
                // daily test on top would put 90 questions on one day, which
                // is how a plan stops being followed.
                $day->role = 'test';
                $day->items[] = [
                    'type' => 'test',
                    'name' => $config['cycle_test_name'] ?? 'Cycle test',
                    'minutes' => (int) ($config['cycle_minutes'] ?? 45),
                    'question_count' => (int) ($config['cycle_questions'] ?? 50),
                ];

                continue;
            }

            // Daily check: short, on every day that has new material.
            if (($config['daily_questions'] ?? 0) > 0 && $day->items !== []) {
                $day->items[] = [
                    'type' => 'test',
                    'name' => 'Daily test',
                    'minutes' => (int) ($config['daily_minutes'] ?? 15),
                    'question_count' => (int) $config['daily_questions'],
                ];
            }
        }

        return $days;
    }

    /**
     * Study dates, honouring weekly off-days.
     *
     * @return list<CarbonImmutable>
     */
    private function studyDates(PlanRequest $request): array
    {
        $dates = [];
        $date = CarbonImmutable::parse($request->startDate);
        $end = CarbonImmutable::parse($request->endDate);

        while ($date->lessThanOrEqualTo($end)) {
            if (! in_array($date->dayOfWeek, $request->offDays, true)) {
                $dates[] = $date;
            }

            $date = $date->addDay();
        }

        return $dates;
    }

    /** @param  list<CarbonImmutable>  $dates */
    private function capacityMinutes(array $dates, float $hoursPerDay): int
    {
        return (int) floor(count($dates) * $hoursPerDay * 60 * self::FOCUS_FACTOR);
    }
}
