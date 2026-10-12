<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\QuestionGenerationTarget;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The official TNPSC Group IV mark distribution, as blueprint and as targets.
 *
 * Two things depend on this and both were running on defaults before it
 * existed:
 *
 *   - SelectionEngine weights every question by how much of the paper its
 *     topic is worth. With no blueprint it fell back to 0.5 for everything,
 *     which quietly says Polity and General Science matter equally. They do
 *     not: Polity is fifteen marks and General Science is five.
 *   - The generation backlog needs a number to be short *of*. Without targets
 *     it is an empty list, and an operator is back to guessing.
 *
 * The counts are questions per paper, from the notification. The bank has to
 * hold a multiple of that, or every paper drawn is the same paper.
 */
final class TnpscBlueprintSeeder extends Seeder
{
    /**
     * Unit name fragment => questions per paper.
     *
     * Matched on a fragment rather than an exact string because the seeded
     * unit names carry roman numerals and em-dashes that are easy to drift.
     */
    private const BLUEPRINT = [
        // Part A: 100 questions across three sections the scheme does not
        // split, so evenly. Written and generated in Tamil.
        'Tamil Grammar' => [33, 'ta'],
        'Tamil Literature' => [33, 'ta'],
        'Tamil Scholars' => [34, 'ta'],
        // Part B. Unit-wise counts as published by TNPSC coaching sources;
        // the official scheme lists topic areas but not counts.
        'General Science' => [5, 'en'],
        'Geography' => [5, 'en'],
        'Culture of India' => [10, 'en'],
        'Indian Polity' => [15, 'en'],
        'Development Administration' => [20, 'en'],
        'Socio-Political Movements' => [20, 'en'],
        'Aptitude' => [25, 'en'],
    ];

    /**
     * How many papers' worth of questions the bank should hold per topic.
     *
     * Ten means a topic worth 15 marks needs 150 approved questions, so ten
     * distinct papers can be drawn before anything repeats. Lower and the
     * exposure penalty has nothing to work with.
     */
    private const POOL_DEPTH = 10;

    public function run(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $exam = Exam::query()->where('code', 'TNPSC_G4')->first();

            if ($exam === null) {
                return;
            }

            $total = array_sum(array_column(self::BLUEPRINT, 0));
            $created = 0;

            // Only this exam's units: a fragment like "Geography" would
            // otherwise match the first such unit of any exam in the table.
            $units = DB::table('exam_units as u')
                ->join('syllabus_versions as v', 'v.id', '=', 'u.syllabus_version_id')
                ->join('syllabi as s', 's.id', '=', 'v.syllabus_id')
                ->where('s.exam_id', $exam->getKey())
                ->select('u.id', 'u.name')
                ->get();

            $scoredUnitIds = [];

            foreach (self::BLUEPRINT as $fragment => [$questionsPerPaper, $languageCode]) {
                $unit = $units->first(static fn ($u): bool => str_contains($u->name, $fragment));
                $language = Language::byCode($languageCode);

                if ($unit === null || $language === null) {
                    continue;
                }

                $scoredUnitIds[] = $unit->id;

                // The weight SelectionEngine reads: this unit's share of the
                // paper, not an equal slice of the syllabus.
                DB::table('exam_units')
                    ->where('id', $unit->id)
                    ->update(['weight' => round($questionsPerPaper / $total, 4)]);

                $topics = DB::table('exam_topics')
                    ->where('exam_unit_id', $unit->id)
                    ->whereNotNull('canonical_topic_id')
                    ->pluck('canonical_topic_id')
                    ->unique()
                    ->values();

                if ($topics->isEmpty()) {
                    continue;
                }

                // A unit's pool is split across its topics, rounded up so the
                // unit total is never short because of division.
                $perTopic = (int) ceil(($questionsPerPaper * self::POOL_DEPTH) / $topics->count());

                foreach ($topics as $topicId) {
                    QuestionGenerationTarget::query()->updateOrCreate(
                        [
                            'exam_id' => $exam->getKey(),
                            'canonical_topic_id' => $topicId,
                            'language_id' => $language->getKey(),
                            'difficulty' => null,
                        ],
                        [
                            'organization_id' => null,
                            'target_count' => $perTopic,
                            // Heavier units get worked first when the backlog
                            // is longer than the content team.
                            'priority' => min(10, max(1, (int) round($questionsPerPaper / 2))),
                            'is_active' => true,
                        ],
                    );

                    $created++;
                }
            }

            $this->retireUnscoredUnits($exam, $units->pluck('id')->diff($scoredUnitIds)->values()->all(), $scoredUnitIds);

            $this->command?->info("TNPSC Group IV blueprint: {$created} generation targets.");
        });
    }

    /**
     * A unit the paper does not score (Current Events, in Group IV) weighs
     * nothing and orders nothing. Its targets are deactivated rather than
     * deleted, and a topic that also sits under a scored unit keeps its target.
     *
     * @param  list<string>  $unscoredUnitIds
     * @param  list<string>  $scoredUnitIds
     */
    private function retireUnscoredUnits(Exam $exam, array $unscoredUnitIds, array $scoredUnitIds): void
    {
        if ($unscoredUnitIds === []) {
            return;
        }

        DB::table('exam_units')->whereIn('id', $unscoredUnitIds)->update(['weight' => 0]);

        $stillScored = DB::table('exam_topics')
            ->whereIn('exam_unit_id', $scoredUnitIds)
            ->whereNotNull('canonical_topic_id')
            ->pluck('canonical_topic_id');

        $retired = DB::table('exam_topics')
            ->whereIn('exam_unit_id', $unscoredUnitIds)
            ->whereNotNull('canonical_topic_id')
            ->pluck('canonical_topic_id')
            ->diff($stillScored);

        QuestionGenerationTarget::query()
            ->where('exam_id', $exam->getKey())
            ->whereIn('canonical_topic_id', $retired->all())
            ->update(['is_active' => false]);
    }
}
