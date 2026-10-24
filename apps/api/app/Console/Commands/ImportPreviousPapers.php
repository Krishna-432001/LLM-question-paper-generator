<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pyq\PyqAnalyzer;
use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\PreviousYearPaper;
use App\Models\PreviousYearQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Loads previous years' papers that were already read and classified
 * elsewhere — every question with its syllabus topic — and rebuilds the topic
 * frequencies the prediction mock is weighted by.
 *
 * The file:
 *   {
 *     "exam": "TNPSC_G4",
 *     "papers": [{
 *       "year": 2019, "paper_name": "TNPSC Group 4 2019", "notes": "...",
 *       "questions": [{
 *         "number": 1, "text": "...", "options": ["...", "...", "...", null],
 *         "answer": "B" | null, "topic": "<canonical topic name or slug>",
 *         "type": "mcq4", "difficulty": "medium" | null
 *       }]
 *     }]
 *   }
 *
 * The papers are platform content: every workspace's prediction reads them.
 * Loading a paper again replaces its questions, so a corrected file can be
 * loaded over an earlier one without doubling any count.
 */
final class ImportPreviousPapers extends Command
{
    protected $signature = 'pyq:import-papers
        {file : JSON file of classified previous-year papers}
        {--dry-run : Check the file and report, change nothing}';

    protected $description = 'Load classified previous-year papers and rebuild the topic frequencies behind prediction mocks';

    /** Each of the 200 questions carries 1.5 of the paper's 300 marks. */
    private const MARKS = 1.5;

    public function handle(PyqAnalyzer $analyzer): int
    {
        try {
            $data = json_decode((string) file_get_contents((string) $this->argument('file')), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('The file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        return TenantContext::withoutTenancy(function () use ($data, $analyzer): int {
            $exam = Exam::query()->where('code', $data['exam'] ?? '')->first();

            if ($exam === null) {
                $this->error("No exam with code {$data['exam']}.");

                return self::FAILURE;
            }

            $topics = CanonicalTopic::query()->get(['id', 'name', 'slug']);
            $topicIds = $topics->pluck('id', 'name')->all() + $topics->pluck('id', 'slug')->all();
            $papers = $data['papers'] ?? [];
            $problems = $papers === [] ? ['The file has no papers.'] : [];

            foreach ($papers as $p => $paper) {
                foreach ($this->problemsWith($paper, $topicIds) as $problem) {
                    $problems[] = ($paper['paper_name'] ?? 'Paper '.($p + 1)).": {$problem}";
                }
            }

            if ($problems !== []) {
                foreach (array_slice($problems, 0, 30) as $problem) {
                    $this->error($problem);
                }

                return self::FAILURE;
            }

            foreach ($papers as $paper) {
                $count = count($paper['questions']);

                if ($this->option('dry-run')) {
                    $this->line("{$paper['paper_name']}: {$count} questions would be loaded.");

                    continue;
                }

                DB::transaction(function () use ($paper, $count, $exam, $topicIds): void {
                    $row = PreviousYearPaper::query()->updateOrCreate(
                        ['exam_id' => $exam->getKey(), 'year' => $paper['year'], 'session' => null, 'paper_name' => $paper['paper_name']],
                        [
                            'organization_id' => null,
                            'total_questions' => $count,
                            'parsed_questions' => $count,
                            'status' => 'parsed',
                            'notes' => $paper['notes'] ?? null,
                        ],
                    );

                    $row->questions()->delete();

                    foreach ($paper['questions'] as $q) {
                        $options = array_pad(array_values((array) ($q['options'] ?? [])), 4, null);

                        PreviousYearQuestion::query()->create([
                            'organization_id' => null,
                            'paper_id' => $row->getKey(),
                            'question_number' => (int) $q['number'],
                            'question_text' => trim($q['text']),
                            'option_a' => $options[0],
                            'option_b' => $options[1],
                            'option_c' => $options[2],
                            'option_d' => $options[3],
                            // Unknown where no answer key was ever published.
                            'correct_option' => $q['answer'] ?? null,
                            'canonical_topic_id' => $topicIds[$q['topic']],
                            'question_type' => $q['type'] ?? 'mcq4',
                            'difficulty' => $q['difficulty'] ?? null,
                            'marks' => self::MARKS,
                            'question_hash' => PreviousYearQuestion::hashFor($q['text']),
                            // Classified question by question before loading,
                            // not guessed here by the topic mapper.
                            'mapping_source' => 'analysis',
                        ]);
                    }
                });

                $this->line("{$paper['paper_name']}: {$count} questions loaded.");
            }

            if ($this->option('dry-run')) {
                return self::SUCCESS;
            }

            $rebuilt = $analyzer->rebuild($exam->getKey());
            $this->info(sprintf(
                'Topic frequencies rebuilt: %d topics from %d questions over %d years.',
                $rebuilt['topics'],
                $rebuilt['questions'],
                $rebuilt['years'],
            ));

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $paper
     * @param  array<string, string>  $topicIds  by name and by slug
     * @return list<string>
     */
    private function problemsWith(array $paper, array $topicIds): array
    {
        $problems = [];
        $year = $paper['year'] ?? null;

        if (! is_int($year) || $year < 1950 || $year > 2100) {
            $problems[] = 'the year is missing or not a year';
        }

        if (trim((string) ($paper['paper_name'] ?? '')) === '') {
            $problems[] = 'the paper has no name';
        }

        if (($paper['questions'] ?? []) === []) {
            $problems[] = 'the paper has no questions';
        }

        $numbers = [];

        foreach ($paper['questions'] ?? [] as $i => $q) {
            $where = 'question '.($q['number'] ?? '#'.($i + 1));

            if (! is_int($q['number'] ?? null) || $q['number'] < 1) {
                $problems[] = "{$where}: the question number is missing";
            } elseif (isset($numbers[$q['number']])) {
                $problems[] = "{$where}: the number is used twice";
            }

            $numbers[$q['number'] ?? -$i] = true;

            if (trim((string) ($q['text'] ?? '')) === '') {
                $problems[] = "{$where}: the question text is empty";
            }

            if (! isset($topicIds[$q['topic'] ?? ''])) {
                $problems[] = "{$where}: unknown topic \"".($q['topic'] ?? '').'"';
            }

            if (($q['answer'] ?? null) !== null && ! in_array($q['answer'], ['A', 'B', 'C', 'D'], true)) {
                $problems[] = "{$where}: the answer must be A, B, C, D or null";
            }

            if (count((array) ($q['options'] ?? [])) > 4) {
                $problems[] = "{$where}: a question has at most four options";
            }
        }

        return $problems;
    }
}
