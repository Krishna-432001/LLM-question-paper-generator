<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\ExamBlueprint;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionTranslation;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Loads ready-made full papers — the 2026 prediction model papers — as timed
 * tests in the exam's pattern, in their printed order, for the users named.
 *
 * The file:
 *   {
 *     "exam": "TNPSC_G4",
 *     "papers": [{
 *       "title": "TNPSC Group 4 · 2026 Prediction Model Paper 1",
 *       "questions": [{
 *         "num": 1, "section": "<a blueprint part's exact name>", "topic": "<canonical topic slug>",
 *         "format": "mcq4", "difficulty": "easy", "answer": "B",
 *         "en": {"q": "...", "options": ["A", "B", "C", "D"]} | null,
 *         "ta": {"q": "...", "options": [...]},
 *         "explanation": "... Source: Std 8 Social Science, p.49"
 *       }]
 *     }]
 *   }
 *
 * The questions were written and checked outside the bank, so they arrive
 * approved and are kept out of it: with no exam mapping, the selection engine
 * never deals them into another paper, and each model paper stays unseen
 * until it is taken. A part set in Tamil (Group IV Part A) prints in Tamil
 * alone; the other parts print English then Tamil, as the booklet does.
 *
 * Loading again is safe. A user who already has a paper of that title is
 * skipped, and a paper already loaded for anyone reuses its questions rather
 * than copying them.
 */
final class ImportModelPapers extends Command
{
    protected $signature = 'tests:import-model-papers
        {file : JSON file of full model papers}
        {--user=* : Email of a user to give the papers to (repeatable)}
        {--replace : Remove copies already loaded from an earlier file (refused once anyone has attempted one), then load}
        {--dry-run : Check the file and report, change nothing}';

    protected $description = 'Load ready-made full model papers as shared timed tests in the exam pattern';

    private const FORMATS = ['mcq4', 'statement_based', 'assertion_reason', 'match_the_following'];

    private const DIFFICULTIES = ['easy', 'medium', 'hard'];

    private const BLUEPRINT = 'TNPSC Group IV — Standard Pattern';

    public function handle(): int
    {
        try {
            $data = json_decode((string) file_get_contents((string) $this->argument('file')), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('The file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        return TenantContext::withoutTenancy(function () use ($data): int {
            $exam = Exam::query()->where('code', $data['exam'] ?? '')->first();
            $blueprint = ExamBlueprint::query()->where('name', self::BLUEPRINT)->with('sections')->first();
            $english = Language::byCode('en');
            $tamil = Language::byCode('ta');

            if ($exam === null || $blueprint === null) {
                $this->error($exam === null ? "No exam with code {$data['exam']}." : 'No blueprint named "'.self::BLUEPRINT.'".');

                return self::FAILURE;
            }

            $sections = $blueprint->sections->keyBy('name');
            $users = [];

            foreach ((array) $this->option('user') as $email) {
                $user = User::query()->where('email', $email)->first();
                $organization = $user?->defaultOrganization();

                if ($organization === null) {
                    $this->error("No user with an organization for {$email}.");

                    return self::FAILURE;
                }

                $users[] = [$user, $organization];
            }

            if ($users === []) {
                $this->error('Name at least one --user to give the papers to.');

                return self::FAILURE;
            }

            $problems = [];

            foreach ($data['papers'] ?? [] as $p => $paper) {
                foreach ($this->problemsWith($paper, $sections->all()) as $problem) {
                    $problems[] = 'Paper '.($p + 1).": {$problem}";
                }
            }

            if ($problems !== [] || ($data['papers'] ?? []) === []) {
                foreach ($problems ?: ['The file has no papers.'] as $problem) {
                    $this->error($problem);
                }

                return self::FAILURE;
            }

            if ($this->option('replace')) {
                $titles = array_column($data['papers'], 'title');
                $loaded = Test::query()->whereIn('title', $titles)->where('selection_profile->kind', 'model_paper')->pluck('id');

                // A paper someone has sat is their record: its questions and
                // answers stay. Replacing is for a paper nobody has used yet.
                if (DB::table('test_attempts')->whereIn('test_id', $loaded)->exists()) {
                    $this->error('A loaded copy has already been attempted, so it is kept. Load the new file under new titles instead.');

                    return self::FAILURE;
                }

                if (! $this->option('dry-run') && $loaded->isNotEmpty()) {
                    DB::transaction(function () use ($loaded): void {
                        $questionIds = DB::table('test_questions')->whereIn('test_id', $loaded)->pluck('question_id');
                        Test::query()->whereIn('id', $loaded)->delete();
                        // Only questions no other paper uses; they were loaded
                        // with these papers and serve nothing else.
                        Question::query()
                            ->whereIn('id', $questionIds)
                            ->where('origin', 'human_authored')
                            ->whereNotExists(fn ($q) => $q->from('test_questions')->whereColumn('test_questions.question_id', 'questions.id'))
                            ->delete();
                    });
                }

                $this->line(sprintf('%d loaded %s.', $loaded->count(), $this->option('dry-run') ? 'copies would be replaced' : 'copies removed'));
            }

            $versionId = DB::table('syllabus_versions as v')
                ->join('syllabi as s', 's.id', '=', 'v.syllabus_id')
                ->where('s.exam_id', $exam->getKey())
                ->where('v.status', 'active')
                ->value('v.id');

            foreach ($data['papers'] as $paper) {
                foreach ($users as [$user, $organization]) {
                    $exists = Test::query()
                        ->where('title', $paper['title'])
                        ->where('created_by', $user->getKey())
                        ->exists();

                    if ($exists) {
                        $this->line("{$paper['title']}: {$user->email} already has it.");

                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $this->line("{$paper['title']}: would be given to {$user->email}.");

                        continue;
                    }

                    DB::transaction(function () use ($paper, $user, $organization, $exam, $blueprint, $sections, $versionId, $english, $tamil): void {
                        $pins = $this->questionsFor($paper, $user, $english, $tamil);
                        $partTotals = [];

                        foreach ($paper['questions'] as $q) {
                            $partTotals[$q['section']] = ($partTotals[$q['section']] ?? 0) + 1;
                        }

                        $test = Test::query()->create([
                            'organization_id' => $organization->getKey(),
                            'created_by' => $user->getKey(),
                            'exam_id' => $exam->getKey(),
                            'blueprint_id' => $blueprint->getKey(),
                            'syllabus_version_id' => $versionId,
                            'type' => 'prediction',
                            'title' => $paper['title'],
                            'language_id' => $english->getKey(),
                            'language_mode' => Test::MODE_BILINGUAL,
                            'secondary_language_id' => $tamil->getKey(),
                            'question_count' => count($paper['questions']),
                            'total_marks' => array_sum(array_map(
                                static fn (string $name, int $n): float => $n * (float) $sections[$name]->marks_per_question,
                                array_keys($partTotals),
                                $partTotals,
                            )),
                            'duration_minutes' => 180,
                            'negative_marking' => 0,
                            'selection_profile' => [
                                'kind' => 'model_paper',
                                'source' => basename((string) $this->argument('file')),
                                'sections' => array_map(
                                    static fn (string $name, int $n): array => ['name' => $name, 'asked' => $n, 'selected' => $n, 'gaps' => []],
                                    array_keys($partTotals),
                                    $partTotals,
                                ),
                            ],
                            'status' => 'ready',
                            // Shared, so everyone in the workspace finds it under Tests.
                            'is_shared' => true,
                        ]);

                        foreach ($paper['questions'] as $i => $q) {
                            $section = $sections[$q['section']];
                            $tamilOnly = $section->language_id !== null;

                            TestQuestion::query()->create([
                                'test_id' => $test->getKey(),
                                'question_id' => $pins[$i]['question_id'],
                                'question_version' => 1,
                                'question_translation_id' => $tamilOnly ? $pins[$i]['ta'] : ($pins[$i]['en'] ?? $pins[$i]['ta']),
                                'secondary_question_translation_id' => $tamilOnly || $pins[$i]['en'] === null ? null : $pins[$i]['ta'],
                                'fixed_language_id' => $section->language_id,
                                'sequence' => (int) $q['num'],
                                'section' => $section->name,
                                'marks' => $section->marks_per_question,
                            ]);
                        }
                    });

                    $this->info("{$paper['title']}: given to {$user->email}.");
                }
            }

            return self::SUCCESS;
        });
    }

    /**
     * The question and translation rows of each question of a paper, in paper
     * order: those of a copy already loaded for someone, or new ones.
     *
     * @param  array<string, mixed>  $paper
     * @return list<array{question_id: string, en: ?string, ta: ?string}>
     */
    private function questionsFor(array $paper, User $user, Language $english, Language $tamil): array
    {
        $loaded = Test::query()
            ->where('title', $paper['title'])
            ->where('selection_profile->kind', 'model_paper')
            ->orderBy('created_at')
            ->first();

        if ($loaded !== null) {
            return TestQuestion::query()
                ->where('test_id', $loaded->getKey())
                ->orderBy('sequence')
                ->get()
                ->map(static fn (TestQuestion $tq): array => [
                    'question_id' => $tq->question_id,
                    'en' => $tq->fixed_language_id === null ? $tq->question_translation_id : null,
                    'ta' => $tq->fixed_language_id === null ? $tq->secondary_question_translation_id : $tq->question_translation_id,
                ])
                ->all();
        }

        $topics = CanonicalTopic::query()->pluck('id', 'slug');
        $letters = ['A', 'B', 'C', 'D'];
        $pins = [];

        foreach ($paper['questions'] as $q) {
            $master = isset($q['en']) ? $english : $tamil;

            $question = Question::query()->create([
                'organization_id' => null,
                'format' => $q['format'],
                // Written and checked by hand (each key solved blind by a
                // second reader), not generated from a book chunk.
                'origin' => 'human_authored',
                'status' => 'APPROVED',
                'master_language_id' => $master->getKey(),
                'difficulty' => $q['difficulty'],
                'bloom_level' => 'remember',
                'canonical_topic_id' => $topics[$q['topic']],
                'created_by' => $user->getKey(),
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
            ]);

            foreach ($letters as $position => $key) {
                QuestionOption::query()->create([
                    'question_id' => $question->getKey(),
                    'option_key' => $key,
                    'position' => $position + 1,
                    'is_correct' => $key === $q['answer'],
                ]);
            }

            $rows = ['en' => null, 'ta' => null];

            foreach (['en' => $english, 'ta' => $tamil] as $code => $language) {
                if (! isset($q[$code])) {
                    continue;
                }

                $rows[$code] = QuestionTranslation::query()->create([
                    'question_id' => $question->getKey(),
                    'language_id' => $language->getKey(),
                    'is_master' => $language->is($master),
                    'source_language_id' => $master->getKey(),
                    'question_text' => trim($q[$code]['q']),
                    'option_a' => trim($q[$code]['options'][0]),
                    'option_b' => trim($q[$code]['options'][1]),
                    'option_c' => trim($q[$code]['options'][2]),
                    'option_d' => trim($q[$code]['options'][3]),
                    // One explanation, written once; shown with either language.
                    'explanation' => trim((string) ($q['explanation'] ?? '')),
                    'translation_status' => 'HUMAN_APPROVED',
                    'review_status' => 'approved',
                ])->getKey();
            }

            $pins[] = ['question_id' => $question->getKey(), 'en' => $rows['en'], 'ta' => $rows['ta']];
        }

        return $pins;
    }

    /**
     * @param  array<string, mixed>  $paper
     * @param  array<string, mixed>  $sections  blueprint parts by name
     * @return list<string>
     */
    private function problemsWith(array $paper, array $sections): array
    {
        $problems = [];
        $questions = $paper['questions'] ?? [];
        $counts = [];

        if (trim((string) ($paper['title'] ?? '')) === '') {
            $problems[] = 'the paper has no title';
        }

        foreach ($questions as $i => $q) {
            $where = 'question '.($q['num'] ?? $i + 1);
            $section = $sections[$q['section'] ?? ''] ?? null;
            $counts[$q['section'] ?? ''] = ($counts[$q['section'] ?? ''] ?? 0) + 1;

            if (($q['num'] ?? null) !== $i + 1) {
                $problems[] = "{$where}: questions must be numbered 1, 2, 3 … in order";
            }

            if ($section === null) {
                $problems[] = "{$where}: unknown part \"".($q['section'] ?? '').'"';
            } elseif ($section->language_id !== null && ! isset($q['ta'])) {
                $problems[] = "{$where}: its part is set in Tamil but it has no Tamil text";
            }

            if (! CanonicalTopic::query()->where('slug', $q['topic'] ?? '')->exists()) {
                $problems[] = "{$where}: unknown topic \"".($q['topic'] ?? '').'"';
            }

            if (! in_array($q['answer'] ?? null, ['A', 'B', 'C', 'D'], true)) {
                $problems[] = "{$where}: the answer must be A, B, C or D";
            }

            if (! in_array($q['difficulty'] ?? null, self::DIFFICULTIES, true)) {
                $problems[] = "{$where}: difficulty must be easy, medium or hard";
            }

            if (! in_array($q['format'] ?? null, self::FORMATS, true)) {
                $problems[] = "{$where}: unknown format \"".($q['format'] ?? '').'"';
            }

            if (! isset($q['ta']) && ! isset($q['en'])) {
                $problems[] = "{$where}: it needs an en or a ta version";
            }

            foreach (['en', 'ta'] as $language) {
                if (! isset($q[$language])) {
                    continue;
                }

                $options = array_map(static fn ($o): string => trim((string) $o), (array) ($q[$language]['options'] ?? []));

                if (trim((string) ($q[$language]['q'] ?? '')) === '') {
                    $problems[] = "{$where}: the {$language} question is empty";
                }

                if (count($options) !== 4 || in_array('', $options, true)) {
                    $problems[] = "{$where}: the {$language} version needs four non-empty options";
                } elseif (count(array_unique($options)) !== 4) {
                    $problems[] = "{$where}: two {$language} options are the same";
                }
            }
        }

        foreach ($sections as $name => $section) {
            if (($counts[$name] ?? 0) !== (int) $section->question_count) {
                $problems[] = sprintf('part "%s" has %d questions; the pattern sets %d', $name, $counts[$name] ?? 0, $section->question_count);
            }
        }

        return $problems;
    }
}
