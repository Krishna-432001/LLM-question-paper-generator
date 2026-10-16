<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Domain\Tests\Exceptions\ExamPatternShortfall;
use App\Models\Exam;
use App\Models\ExamBlueprint;
use App\Models\Test;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A full mock in the real exam's pattern: its parts, in order, each with its
 * own question count, marks and language — TNPSC Group IV is Part A Tamil
 * (100), General Studies (75) and Aptitude (25), 1.5 marks each, 180 minutes.
 *
 * Inside a part, each syllabus unit gets its share of the part's questions
 * by its weight in the paper (Polity 15, General Science 5), so the mock
 * covers the syllabus the way the exam does rather than whatever topic the
 * bank happens to hold most of.
 *
 * A pattern the bank cannot fill is refused with the count for every part,
 * so a student never sits 38 questions believing it is the 200-question
 * paper. On request it builds the shorter mock instead, its time scaled to
 * match.
 */
final class AssembleExamPatternTestAction
{
    public function __construct(private readonly AssembleTestAction $assemble) {}

    /**
     * @param  array<string, mixed>  $attributes  exam_id, language_id, language_mode,
     *                                            secondary_language_id, type, user_id,
     *                                            organization_id, title, allow_short
     */
    public function execute(array $attributes): Test
    {
        $examId = (string) $attributes['exam_id'];
        $blueprint = $this->blueprintFor($examId)
            ?? throw new UnprocessableEntityHttpException('This exam has no exam pattern set up yet.');

        $sections = $this->sectionsOf($blueprint);

        foreach ($sections as $s => $section) {
            foreach ($section['units'] as $u => $unit) {
                $sections[$s]['units'][$u]['available'] = min($unit['needed'], $this->available($attributes, $section, $unit));
            }
        }

        $shortfall = new ExamPatternShortfall(array_map(static fn (array $section): array => [
            'name' => $section['name'],
            'needed' => $section['question_count'],
            'available' => array_sum(array_column($section['units'], 'available')),
            'units' => array_map(static fn (array $unit): array => [
                'name' => $unit['name'],
                'needed' => $unit['needed'],
                'available' => $unit['available'],
            ], $section['units']),
        ], $sections));

        $total = (int) $blueprint->total_questions ?: array_sum(array_column($sections, 'question_count'));
        $isShort = $shortfall->availableTotal() < array_sum(array_column($sections, 'question_count'));

        if ($shortfall->availableTotal() === 0 || ($isShort && ! ($attributes['allow_short'] ?? false))) {
            throw $shortfall;
        }

        $type = (string) ($attributes['type'] ?? 'model');
        $examName = (string) Exam::query()->whereKey($examId)->value('name');
        $title = $attributes['title'] ?? $examName.' — '.($type === 'prediction' ? 'Prediction Mock Test' : 'Full Mock Test')
            .($isShort ? " ({$shortfall->availableTotal()} of {$total} questions)" : '');

        // One part per unit; the units of a part share its name, so the
        // paper shows three parts while each unit keeps its own count.
        $parts = [];

        foreach ($sections as $section) {
            foreach ($section['units'] as $unit) {
                if ($unit['available'] === 0) {
                    continue;
                }

                $parts[] = [
                    'name' => $section['name'],
                    'question_count' => $unit['available'],
                    'marks_per_question' => $section['marks_per_question'],
                    'canonical_topic_ids' => $unit['canonical_topic_ids'],
                    'language_id' => $section['language_id'],
                    'difficulty_mix' => $section['difficulty_mix'],
                ];
            }
        }

        $test = $this->assemble->execute([
            ...$attributes,
            'type' => $type,
            'title' => $title,
            'question_count' => $shortfall->availableTotal(),
            'blueprint_id' => $blueprint->getKey(),
            'syllabus_version_id' => $blueprint->syllabus_version_id,
            'difficulty_mix' => $blueprint->difficulty_mix,
            'negative_marking' => (float) ($blueprint->examPaper->negative_marking ?? 0),
            'duration_minutes' => $blueprint->duration_minutes,
            'sections' => $parts,
        ]);

        // The real paper's time per question, for however many it got.
        if ($test->question_count < $total) {
            $test->forceFill([
                'duration_minutes' => max(1, (int) round($blueprint->duration_minutes * $test->question_count / max(1, $total))),
            ])->save();
        }

        return $test;
    }

    /** The exam's pattern in force: its default blueprint on the active version. */
    private function blueprintFor(string $examId): ?ExamBlueprint
    {
        return ExamBlueprint::query()
            ->whereHas('examPaper.examStage.examVersion', static fn ($v) => $v
                ->where('exam_id', $examId)
                ->where('status', 'active'))
            ->with(['sections', 'examPaper.subjects'])
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * The blueprint's parts, each split into its syllabus units. A blueprint
     * with no parts set up falls back to the paper's subjects and their
     * question counts — the same pattern, stated once less precisely.
     *
     * @return list<array{name: string, question_count: int, marks_per_question: float, language_id: ?string, difficulty_mix: ?array<string, float>, units: list<array{name: string, needed: int, canonical_topic_ids: ?list<string>}>}>
     */
    private function sectionsOf(ExamBlueprint $blueprint): array
    {
        $versionId = (string) $blueprint->syllabus_version_id;
        $paper = $blueprint->examPaper;
        $marks = $paper !== null && (int) $paper->total_questions > 0
            ? (float) $paper->total_marks / (int) $paper->total_questions
            : 1.0;

        $sections = $blueprint->sections->isNotEmpty()
            ? $blueprint->sections->map(static fn ($s): array => [
                'name' => (string) $s->name,
                'subject_id' => $s->exam_subject_id,
                'question_count' => (int) $s->question_count,
                'marks_per_question' => (float) $s->marks_per_question,
                'language_id' => $s->language_id,
                'difficulty_mix' => $s->difficulty_mix,
            ])
            : ($paper?->subjects ?? collect())
                ->filter(static fn ($subject): bool => (int) $subject->question_count > 0)
                ->sortBy('sequence')
                ->map(static fn ($subject): array => [
                    'name' => (string) $subject->name,
                    'subject_id' => $subject->getKey(),
                    'question_count' => (int) $subject->question_count,
                    'marks_per_question' => $marks,
                    'language_id' => null,
                    'difficulty_mix' => null,
                ]);

        return $sections->map(fn (array $section): array => [
            ...$section,
            'units' => $section['subject_id'] === null
                // A part tied to no subject draws on the whole exam.
                ? [['name' => $section['name'], 'needed' => $section['question_count'], 'canonical_topic_ids' => null]]
                : $this->unitsOf((string) $section['subject_id'], $versionId, $section['question_count']),
        ])->values()->all();
    }

    /**
     * A part's units with their share of its questions, by each unit's weight
     * in the paper. Largest remainder, so the shares add up to the part's
     * count exactly; a unit the paper does not score (weight 0) gets none.
     *
     * @return list<array{name: string, needed: int, canonical_topic_ids: list<string>}>
     */
    private function unitsOf(string $examSubjectId, string $versionId, int $count): array
    {
        $units = DB::table('exam_units')
            ->where('exam_subject_id', $examSubjectId)
            ->where('syllabus_version_id', $versionId)
            ->orderBy('sequence')
            ->get(['id', 'name', 'weight']);

        if ($units->isEmpty() || $count <= 0) {
            return [];
        }

        $weighted = $units->filter(static fn ($u): bool => (float) $u->weight > 0);
        // No weights at all: the units share the part evenly.
        $units = $weighted->isNotEmpty() ? $weighted->values() : $units->values();
        $weightOf = static fn ($u): float => $weighted->isNotEmpty() ? (float) $u->weight : 1.0;
        $sum = $units->sum($weightOf) ?: 1.0;

        $shares = $units->map(static fn ($u) => $count * $weightOf($u) / $sum);
        $needed = $shares->map(static fn (float $share): int => (int) floor($share))->all();
        $left = $count - array_sum($needed);
        $order = $shares->map(static fn (float $share, int $i): array => [$share - floor($share), $i])
            ->sortByDesc(static fn (array $r): float => $r[0])
            ->values();

        for ($k = 0; $k < $left; $k++) {
            $needed[$order[$k % max(1, $order->count())][1]]++;
        }

        return $units->map(fn ($u, int $i): array => [
            'name' => (string) $u->name,
            'needed' => $needed[$i],
            'canonical_topic_ids' => DB::table('exam_topics')
                ->where('exam_unit_id', $u->id)
                ->whereNotNull('canonical_topic_id')
                ->distinct()
                ->pluck('canonical_topic_id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
        ])->filter(static fn (array $unit): bool => $unit['needed'] > 0)->values()->all();
    }

    /**
     * Eligible questions for one unit, in the language(s) its part prints in.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $unit
     */
    private function available(array $attributes, array $section, array $unit): int
    {
        // An empty topic list would mean "any topic" to the selector.
        if ($unit['canonical_topic_ids'] === []) {
            return 0;
        }

        $counts = $this->assemble->availability([
            ...$attributes,
            'question_count' => $unit['needed'],
            'canonical_topic_ids' => $unit['canonical_topic_ids'],
            ...($section['language_id'] !== null
                ? ['language_id' => $section['language_id'], 'language_mode' => Test::MODE_SINGLE, 'secondary_language_id' => null]
                : []),
        ]);

        return $counts['both'] ?? $counts['primary'];
    }
}
