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

    /**
     * Share of the timeline reserved for full-syllabus revision at the end.
     * New material is spread over the rest, so study runs to about 88% of
     * the plan however long it is.
     */
    private const REVISION_TAIL = 0.12;

    /**
     * Share of each study day given to new material when there is time to
     * spare. The rest is left for the revision and the tests layered on top.
     */
    private const STUDY_SHARE = 0.75;

    /** Longest single sitting on one topic, in minutes. */
    private const SESSION_MAX = 90;

    /** The closing stretch, in days, where mocks come every mock_every days. */
    private const FINAL_STRETCH = 21;

    /** Before the closing stretch, at most one mock in this many days. */
    private const EARLY_MOCK_GAP = 7;

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
        $window = $this->studyWindow(count($studyDates));
        $capacity = $this->capacityMinutes($window, $request->hoursPerDay);

        [$scheduled, $dropped] = $this->fitToCapacity($topics, $capacity, $request);

        $sessions = $this->allot($scheduled, $capacity, $window);
        $days = $this->binPack($sessions, $studyDates, $window);
        $days = $this->injectRepetition($days, $scheduled);

        // Tests are placed before the revision rounds, so a mock day is not
        // also given a full day of revision.
        $mockDays = $this->mockDays($days, $request->testConfig);
        $days = $this->fillRevision($days, $scheduled, $mockDays, $request);
        $days = $this->injectAssessments($days, $request, $mockDays);

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
        $leaves = ExamTopic::query()
            ->where('syllabus_version_id', $request->syllabusVersionId)
            ->leaves()
            ->with('examUnit')
            ->get();

        $perUnit = $leaves->countBy('exam_unit_id');

        return $leaves
            ->map(function (ExamTopic $topic) use ($request, $perUnit): array {
                $mastery = $request->masteryByTopic[$topic->canonical_topic_id] ?? 0.5;
                // With no past-paper analysis every topic is an even bet. With
                // one, a topic it never found asked is the weakest bet.
                $prediction = $request->predictionByTopic === []
                    ? 0.5
                    : ($request->predictionByTopic[$topic->canonical_topic_id] ?? 0.0);
                $weakness = 1 - $mastery;
                // The topic's share of the paper: its unit's weight split over
                // the unit's topics. A topic's own weight is relative to its
                // subject, so it cannot compare Tamil with Aptitude.
                $unitWeight = (float) ($topic->examUnit?->weight ?? 0);
                $share = $unitWeight > 0
                    ? $unitWeight / max(1, (int) $perUnit[$topic->exam_unit_id])
                    : (float) $topic->weight;

                return [
                    'exam_topic_id' => $topic->getKey(),
                    'canonical_topic_id' => $topic->canonical_topic_id,
                    'name' => $topic->name,
                    'unit_id' => $topic->exam_unit_id,
                    // Weak topics get more minutes, not just earlier slots.
                    'minutes' => (int) ceil($topic->estimated_minutes * (1 + $weakness * 0.5)),
                    'share' => $share,
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
        // $capacity is the study window's alone: the tail is held back for
        // revision, not new material.
        $available = $capacity;

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
     * How the study window's time is shared out. Every topic gets its own
     * first-pass minutes; time to spare goes to the topics that carry more
     * of the paper and are likelier to be asked, so a heavy topic is studied
     * longer, over more sittings. Each topic is then cut into sittings no
     * longer than a day's share, which is what lets the sittings cover every
     * day of the window.
     *
     * @param  list<array<string, mixed>>  $topics  in study order
     * @return list<array<string, mixed>> sittings, in study order
     */
    private function allot(array $topics, int $capacity, int $window): array
    {
        if ($topics === []) {
            return [];
        }

        $base = array_sum(array_column($topics, 'minutes'));
        $spare = max(0, (int) floor($capacity * self::STUDY_SHARE) - $base);
        $weights = array_map(
            static fn (array $t): float => $t['share'] * max(0.05, $t['priority']),
            $topics,
        );
        $weightTotal = array_sum($weights) ?: 1.0;

        $totals = [];

        foreach ($topics as $i => $topic) {
            $totals[$i] = $topic['minutes'] + (int) floor($spare * $weights[$i] / $weightTotal);
        }

        $session = max(1, min(self::SESSION_MAX, intdiv(array_sum($totals), max(1, $window))));
        $sessions = [];

        foreach ($topics as $i => $topic) {
            $count = (int) ceil($totals[$i] / $session);

            for ($n = 0; $n < $count; $n++) {
                // Near-equal sittings, none longer than $session.
                $minutes = intdiv($totals[$i], $count) + ($n < $totals[$i] % $count ? 1 : 0);

                $sessions[] = [
                    'type' => 'study',
                    'exam_topic_id' => $topic['exam_topic_id'],
                    'canonical_topic_id' => $topic['canonical_topic_id'],
                    'name' => $topic['name'],
                    'minutes' => $minutes,
                    'session' => $n + 1,
                    'sessions' => $count,
                ];
            }
        }

        return $sessions;
    }

    /**
     * Lays the sittings evenly over the study window: each day takes the
     * sittings that start within its share of the total minutes. No sitting
     * is longer than a share, so no day of the window is left empty. The
     * days after the window are the revision tail.
     *
     * @param  list<array<string, mixed>>  $sessions
     * @param  list<CarbonImmutable>  $dates
     * @return list<ScheduledDay>
     */
    private function binPack(array $sessions, array $dates, int $window): array
    {
        $total = array_sum(array_column($sessions, 'minutes'));
        $perDay = $total / max(1, $window);
        $byDay = [];
        $offset = 0;

        foreach ($sessions as $session) {
            $index = $perDay > 0 ? min($window - 1, (int) floor($offset / $perDay)) : 0;
            $offset += $session['minutes'];
            $key = $session['exam_topic_id'];

            // Two sittings of one topic on one day are one longer sitting.
            if (isset($byDay[$index][$key])) {
                $byDay[$index][$key]['minutes'] += $session['minutes'];
                $byDay[$index][$key]['session'] = $session['session'];

                continue;
            }

            $byDay[$index][$key] = $session;
        }

        $days = [];

        foreach ($dates as $index => $date) {
            $items = array_values($byDay[$index] ?? []);

            $days[] = new ScheduledDay(
                dayNumber: $index + 1,
                date: $date,
                role: $items === [] ? 'revision' : 'new',
                targetMinutes: array_sum(array_column($items, 'minutes')),
                items: $items,
            );
        }

        return $days;
    }

    /**
     * Spaced repetition on a fixed ladder rather than per-item SM-2: a plan
     * has to be printable and predictable a month in advance. The ladder
     * starts when a topic's last sitting is done, not after every sitting.
     *
     * @param  list<ScheduledDay>  $days
     * @param  list<array<string, mixed>>  $topics
     * @return list<ScheduledDay>
     */
    private function injectRepetition(array $days, array $topics): array
    {
        $byNumber = [];
        $firstPass = array_column($topics, 'minutes', 'exam_topic_id');

        foreach ($days as $day) {
            $byNumber[$day->dayNumber] = $day;
        }

        foreach ($days as $day) {
            foreach ($day->items as $item) {
                if ($item['type'] !== 'study' || $item['session'] !== $item['sessions']) {
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
                        'minutes' => $this->revisionMinutes($firstPass[$item['exam_topic_id']] ?? $item['minutes']),
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
     * Revision rounds through the tail. Every day without new material gets
     * a day's worth of quick revision, working down the topics in priority
     * order and starting over when it reaches the end, so the whole syllabus
     * comes round again and again before the exam. A mock day is left to its
     * mock.
     *
     * @param  list<ScheduledDay>  $days
     * @param  list<array<string, mixed>>  $topics
     * @param  array<int, true>  $mockDays
     * @return list<ScheduledDay>
     */
    private function fillRevision(array $days, array $topics, array $mockDays, PlanRequest $request): array
    {
        if ($topics === []) {
            return $days;
        }

        usort($topics, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $budget = (int) floor($request->hoursPerDay * 60 * self::FOCUS_FACTOR);
        $lastStudied = [];
        $cursor = 0;

        foreach ($days as $day) {
            $studies = array_filter($day->items, static fn (array $i): bool => $i['type'] === 'study');

            foreach ($studies as $item) {
                $lastStudied[$item['exam_topic_id']] = $day->dayNumber;
            }

            if ($studies !== [] || isset($mockDays[$day->dayNumber])) {
                continue;
            }

            $onDay = array_flip(array_filter(array_column($day->items, 'exam_topic_id')));
            $minutes = $day->totalMinutes();

            // One pass over the topics at most, so a short syllabus does not
            // put the same topic on a day twice.
            for ($tried = 0; $tried < count($topics); $tried++) {
                $topic = $topics[$cursor];
                $add = $this->revisionMinutes($topic['minutes']);

                if ($day->items !== [] && $minutes + $add > $budget) {
                    break;
                }

                $cursor = ($cursor + 1) % count($topics);

                if (isset($onDay[$topic['exam_topic_id']])) {
                    continue;
                }

                $day->items[] = [
                    'type' => 'revise',
                    'exam_topic_id' => $topic['exam_topic_id'],
                    'canonical_topic_id' => $topic['canonical_topic_id'],
                    'name' => $topic['name'],
                    'minutes' => $add,
                    'repeat_of' => $lastStudied[$topic['exam_topic_id']] ?? null,
                ];
                $minutes += $add;
            }

            $day->role = 'revision';
        }

        return $days;
    }

    /** Revision is a fraction of first-pass time. */
    private function revisionMinutes(int $firstPass): int
    {
        return max(5, (int) round($firstPass * 0.25));
    }

    /**
     * Assessment placement is what distinguishes the five modes.
     *
     * @param  list<ScheduledDay>  $days
     * @param  array<int, true>  $mockDays
     * @return list<ScheduledDay>
     */
    private function injectAssessments(array $days, PlanRequest $request, array $mockDays): array
    {
        $config = $request->testConfig;
        $cycles = $this->cyclesOf($config);

        foreach ($days as $day) {
            // The revision weeks rehearse the real paper: a full mock in the
            // exam's pattern, with nothing else stacked on that day.
            if (isset($mockDays[$day->dayNumber])) {
                $day->role = 'test';
                $day->items[] = [
                    'type' => 'test',
                    'test_kind' => 'mock',
                    'name' => $config['mock_name'] ?? 'Full mock test',
                    'minutes' => (int) ($config['mock_minutes'] ?? 180),
                    'question_count' => isset($config['mock_questions']) ? (int) $config['mock_questions'] : null,
                    'scope_days' => null,
                ];

                continue;
            }

            $cycle = null;

            foreach ($cycles as $candidate) {
                if ($day->dayNumber % $candidate['every'] === 0) {
                    $cycle = $candidate;

                    break;
                }
            }

            if ($cycle !== null) {
                // A cycle-test day carries the cycle test only. Stacking the
                // daily test on top would put 90 questions on one day, which
                // is how a plan stops being followed. It covers what the
                // cycle studied.
                $day->role = 'test';
                $day->items[] = [
                    'type' => 'test',
                    'test_kind' => $cycle['kind'],
                    'name' => $cycle['name'],
                    'minutes' => $cycle['minutes'],
                    'question_count' => $cycle['questions'],
                    // A model examination covers the whole syllabus.
                    'scope_days' => $cycle['kind'] === 'model' ? null : $cycle['every'],
                ];

                continue;
            }

            // Daily check: short, on every day that has material, and on
            // that day's topics.
            if (($config['daily_questions'] ?? 0) > 0 && $day->items !== []) {
                $day->items[] = [
                    'type' => 'test',
                    'test_kind' => 'daily',
                    'name' => 'Daily test',
                    'minutes' => (int) ($config['daily_minutes'] ?? 15),
                    'question_count' => (int) $config['daily_questions'],
                    'scope_days' => 1,
                ];
            }
        }

        return $days;
    }

    /**
     * The cycle tests a mode sets, longest cycle first so a day that closes
     * both a month and a week gets the monthly test. A mode written with a
     * single cycle_days keeps meaning what it always did.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{every: int, kind: string, name: string, questions: int, minutes: int}>
     */
    private function cyclesOf(array $config): array
    {
        $cycles = $config['cycles'] ?? [];

        if ($cycles === [] && (int) ($config['cycle_days'] ?? 0) > 0) {
            $every = (int) $config['cycle_days'];
            $cycles = [[
                'every' => $every,
                'kind' => $config['cycle_kind'] ?? match ($every) {
                    3 => 'three_day',
                    7 => 'weekly',
                    default => 'weekly',
                },
                'name' => $config['cycle_test_name'] ?? 'Cycle test',
                'questions' => (int) ($config['cycle_questions'] ?? 50),
                'minutes' => (int) ($config['cycle_minutes'] ?? 45),
            ]];
        }

        $cycles = array_values(array_filter(
            array_map(static fn (array $c): array => [
                'every' => (int) $c['every'],
                'kind' => (string) $c['kind'],
                'name' => (string) ($c['name'] ?? 'Cycle test'),
                'questions' => (int) ($c['questions'] ?? 50),
                'minutes' => (int) ($c['minutes'] ?? 45),
            ], $cycles),
            static fn (array $c): bool => $c['every'] > 0,
        ));

        usort($cycles, static fn (array $a, array $b): int => $b['every'] <=> $a['every']);

        return $cycles;
    }

    /**
     * The days of the final revision stretch — after the last new topic —
     * that hold a full mock. A cycle test keeps its day; the mocks go on the
     * free days around it. Counting back from the exam, a mock comes every
     * $every-th day through the last three weeks and at most once a week
     * before that. The very last day stays a revision day when the stretch
     * has room. A plan too short to have such a stretch still ends with one
     * mock on its last free day.
     *
     * @param  list<ScheduledDay>  $days
     * @param  array<string, mixed>  $config
     * @return array<int, true> day number => true
     */
    private function mockDays(array $days, array $config): array
    {
        $every = (int) ($config['mock_every'] ?? 0);

        if ($every <= 0 || $days === []) {
            return [];
        }

        $cycles = $this->cyclesOf($config);
        $isFree = static function (ScheduledDay $day) use ($cycles): bool {
            foreach ($cycles as $cycle) {
                if ($day->dayNumber % $cycle['every'] === 0) {
                    return false;
                }
            }

            return true;
        };

        $lastStudy = 0;

        foreach ($days as $day) {
            foreach ($day->items as $item) {
                if ($item['type'] === 'study') {
                    $lastStudy = $day->dayNumber;
                }
            }
        }

        $lastDay = end($days)->dayNumber;
        $free = array_values(array_filter(
            array_reverse($days),
            static fn (ScheduledDay $d): bool => $d->dayNumber > $lastStudy && $isFree($d),
        ));

        if ($free === []) {
            $fallback = array_values(array_filter(array_reverse($days), $isFree));

            return $fallback === [] ? [] : [$fallback[0]->dayNumber => true];
        }

        if (count($free) > 1 && $free[0]->dayNumber === $lastDay) {
            array_shift($free);
        }

        $mocks = [];
        $previous = null;

        foreach ($free as $day) {
            $gap = $lastDay - $day->dayNumber < self::FINAL_STRETCH ? $every : self::EARLY_MOCK_GAP;

            if ($previous === null || $previous - $day->dayNumber >= $gap) {
                $mocks[$day->dayNumber] = true;
                $previous = $day->dayNumber;
            }
        }

        return $mocks;
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

    /**
     * How many of the plan's days take new material: all but the revision
     * tail. A plan of a day or two has no room for a tail.
     */
    private function studyWindow(int $days): int
    {
        if ($days <= 2) {
            return $days;
        }

        return $days - max(1, (int) round($days * self::REVISION_TAIL));
    }

    private function capacityMinutes(int $days, float $hoursPerDay): int
    {
        return (int) floor($days * $hoursPerDay * 60 * self::FOCUS_FACTOR);
    }
}
