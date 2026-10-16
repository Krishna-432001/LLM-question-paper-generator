<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\TenantContext;
use App\Models\BlueprintSection;
use App\Models\ExamBlueprint;
use App\Models\ExamSubject;
use App\Models\Language;
use Illuminate\Database\Seeder;

/**
 * The parts of the TNPSC Group IV paper, as the full mock lays them out.
 *
 * 200 questions, 300 marks, so 1.5 marks each. Part A is the Tamil
 * eligibility-cum-scoring test: set in Tamil for every candidate, and a
 * candidate below 40% of its marks does not have Part B evaluated at all.
 * Part B is General Studies and Aptitude, in the paper's language.
 *
 * Only sets up a pattern that has no parts yet, so parts an administrator
 * has since edited are left alone.
 */
final class TnpscGroup4PatternSeeder extends Seeder
{
    /** Part name => [paper subject, questions, language it is always set in, qualifying % of its marks]. */
    private const SECTIONS = [
        'Part A — Tamil Eligibility-cum-Scoring Test' => ['Part A — Tamil Eligibility-cum-Scoring Test', 100, 'ta', 40.0],
        'Part B — General Studies' => ['General Studies', 75, null, null],
        'Part B — Aptitude and Mental Ability' => ['Aptitude and Mental Ability', 25, null, null],
    ];

    private const MARKS_PER_QUESTION = 1.5;

    public function run(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $blueprint = ExamBlueprint::query()->where('name', 'TNPSC Group IV — Standard Pattern')->first();

            if ($blueprint === null || $blueprint->sections()->exists()) {
                return;
            }

            $sequence = 0;

            foreach (self::SECTIONS as $name => [$subjectName, $count, $languageCode, $qualifying]) {
                BlueprintSection::query()->create([
                    'blueprint_id' => $blueprint->getKey(),
                    'name' => $name,
                    'exam_subject_id' => ExamSubject::query()
                        ->where('exam_paper_id', $blueprint->exam_paper_id)
                        ->where('name', $subjectName)
                        ->value('id'),
                    'question_count' => $count,
                    'marks_per_question' => self::MARKS_PER_QUESTION,
                    'language_id' => $languageCode !== null ? Language::byCode($languageCode)?->getKey() : null,
                    'qualifying_percent' => $qualifying,
                    'sequence' => $sequence++,
                ]);
            }

            $this->command?->info('TNPSC Group IV pattern: '.count(self::SECTIONS).' parts.');
        });
    }
}
