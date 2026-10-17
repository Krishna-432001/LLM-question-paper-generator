<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Questions\DTOs\QuestionCandidate;
use App\Domain\Questions\QuestionWriter;
use App\Domain\Review\ReviewWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\Book;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\Language;
use App\Models\QuestionSource;
use App\Models\QuestionTranslation;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Loads a file of written questions — in English with their Tamil — into the
 * bank, each citing the book page it comes from, filed under its syllabus
 * topic and exam, and sent to the review queue. Nothing reaches a paper
 * until a reviewer approves it.
 *
 * The file:
 *   {
 *     "exam": "TNPSC_G4",
 *     "source": "<a source's exact title>",
 *     "breadcrumb": "Std 10 Social Science — Civics Unit 1: Indian Constitution",
 *     "questions": [{
 *       "topic": "<canonical topic slug>", "format": "mcq4", "difficulty": "easy",
 *       "bloom": "remember", "page": 239, "quote": "<the book's own words>",
 *       "answer": "B",
 *       "en": {"q": "...", "options": ["A text", "B text", "C text", "D text"], "explanation": "..."},
 *       "ta": {"q": "...", "options": [...], "explanation": "..."}
 *     }]
 *   }
 *
 * A question about the Tamil language itself (Group IV Part A: grammar,
 * literature, Tamil scholars) has no English version worth writing. Give it
 * "ta" alone and Tamil becomes its master.
 *
 * A question whose master wording is already in the bank is skipped, so a
 * file can be loaded again after it is extended.
 */
final class ImportQuestions extends Command
{
    protected $signature = 'questions:import
        {file : JSON file of written questions}
        {--dry-run : Check the file and report, change nothing}
        {--max=50 : Refuse a file with more questions than this (one file is one chapter); 0 for no limit}';

    protected $description = 'Load written questions (English with Tamil, or Tamil alone) into the review queue, each citing its book page';

    private const FORMATS = ['mcq4', 'statement_based', 'assertion_reason', 'match_the_following', 'true_false'];

    private const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public function handle(QuestionWriter $writer, ReviewWorkflow $review): int
    {
        try {
            $data = json_decode((string) file_get_contents((string) $this->argument('file')), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('The file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        return TenantContext::withoutTenancy(function () use ($data, $writer, $review): int {
            $exam = Exam::query()->where('code', $data['exam'] ?? '')->first();
            $source = Source::query()->where('title', $data['source'] ?? '')->first();
            $english = Language::byCode('en');
            $tamil = Language::byCode('ta');

            if ($exam === null || $source === null) {
                $this->error($exam === null ? "No exam with code {$data['exam']}." : "No source titled \"{$data['source']}\".");

                return self::FAILURE;
            }

            $book = Book::query()->where('source_id', $source->getKey())->first();
            $versionId = DB::table('syllabus_versions as v')
                ->join('syllabi as s', 's.id', '=', 'v.syllabus_id')
                ->where('s.exam_id', $exam->getKey())
                ->where('v.status', 'active')
                ->value('v.id');

            $questions = $data['questions'] ?? [];
            $problems = [];
            $max = (int) $this->option('max');

            if ($max > 0 && count($questions) > $max) {
                $problems[] = sprintf('The file has %d questions; a chapter may have at most %d (use --max to change).', count($questions), $max);
            }

            foreach ($questions as $i => $q) {
                foreach ($this->problemsWith($q) as $problem) {
                    $problems[] = 'Question '.($i + 1).": {$problem}";
                }
            }

            if ($problems !== []) {
                foreach ($problems as $problem) {
                    $this->error($problem);
                }

                return self::FAILURE;
            }

            $added = 0;
            $skipped = 0;

            foreach ($questions as $i => $q) {
                $already = DB::table('question_translations')
                    ->where('is_master', true)
                    ->where('question_text', trim(($q['en'] ?? $q['ta'])['q']))
                    ->exists();

                if ($already) {
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $added++;

                    continue;
                }

                DB::transaction(function () use ($q, $i, $writer, $review, $exam, $source, $book, $versionId, $english, $tamil, $data): void {
                    $letters = ['A', 'B', 'C', 'D'];
                    $topic = CanonicalTopic::query()->where('slug', $q['topic'])->firstOrFail();
                    $master = $q['en'] ?? $q['ta'];

                    $question = $writer->write(
                        new QuestionCandidate(
                            ref: 'Q'.($i + 1),
                            questionText: trim($master['q']),
                            options: array_combine($letters, array_map('trim', $master['options'])),
                            correctOption: $q['answer'],
                            explanation: trim($master['explanation'] ?? ''),
                            difficulty: $q['difficulty'],
                            bloomLevel: $q['bloom'] ?? 'remember',
                            topic: $topic->name,
                            subtopic: null,
                            sourceRefs: [],
                            supportingQuote: trim($q['quote']),
                            confidence: 1.0,
                            format: $q['format'] ?? 'mcq4',
                        ),
                        'PENDING_REVIEW',
                        [
                            'organization_id' => null,
                            'language_id' => isset($q['en']) ? $english->getKey() : $tamil->getKey(),
                            'canonical_topic_id' => $topic->getKey(),
                            'exam_ids' => [$exam->getKey()],
                            'syllabus_version_id' => $versionId,
                        ],
                    );

                    // The citation a student can check against the printed book.
                    QuestionSource::query()->create([
                        'question_id' => $question->getKey(),
                        'source_id' => $source->getKey(),
                        'book_id' => $book?->getKey(),
                        'breadcrumb' => $data['breadcrumb'] ?? $source->title,
                        'page_start' => (int) $q['page'],
                        'page_end' => (int) ($q['page_end'] ?? $q['page']),
                        'supporting_quote' => trim($q['quote']),
                        'is_primary' => true,
                    ]);

                    if (isset($q['en'], $q['ta'])) {
                        QuestionTranslation::query()->create([
                            'question_id' => $question->getKey(),
                            'language_id' => $tamil->getKey(),
                            'is_master' => false,
                            'source_language_id' => $english->getKey(),
                            'question_text' => trim($q['ta']['q']),
                            'option_a' => trim($q['ta']['options'][0]),
                            'option_b' => trim($q['ta']['options'][1]),
                            'option_c' => trim($q['ta']['options'][2]),
                            'option_d' => trim($q['ta']['options'][3]),
                            'explanation' => trim($q['ta']['explanation'] ?? ''),
                            // Written and checked by the author, not yet by a person.
                            'translation_status' => 'AI_VALIDATED',
                        ]);
                    }

                    $review->enqueue($question, priority: 6);
                });

                $added++;
            }

            $this->info(sprintf(
                '%d %s, %d already in the bank.',
                $added,
                $this->option('dry-run') ? 'would be added' : 'added to the review queue',
                $skipped,
            ));

            return self::SUCCESS;
        });
    }

    /**
     * @param  array<string, mixed>  $q
     * @return list<string>
     */
    private function problemsWith(array $q): array
    {
        $problems = [];

        if (! CanonicalTopic::query()->where('slug', $q['topic'] ?? '')->exists()) {
            $problems[] = 'unknown topic "'.($q['topic'] ?? '').'"';
        }

        if (! in_array($q['answer'] ?? null, ['A', 'B', 'C', 'D'], true)) {
            $problems[] = 'the answer must be A, B, C or D';
        }

        if (! in_array($q['difficulty'] ?? null, self::DIFFICULTIES, true)) {
            $problems[] = 'difficulty must be easy, medium or hard';
        }

        if (! in_array($q['format'] ?? 'mcq4', self::FORMATS, true)) {
            $problems[] = 'unknown format "'.$q['format'].'"';
        }

        if (! is_int($q['page'] ?? null)) {
            $problems[] = 'the book page is missing';
        }

        if (trim((string) ($q['quote'] ?? '')) === '') {
            $problems[] = 'the supporting quote from the book is missing';
        }

        if (! isset($q['en']) && ! isset($q['ta'])) {
            $problems[] = 'the question needs an en or a ta version';
        }

        foreach (['en', 'ta'] as $language) {
            if (! isset($q[$language])) {
                continue;
            }

            $text = $q[$language] ?? [];
            $options = array_map(static fn ($o): string => trim((string) $o), (array) ($text['options'] ?? []));

            if (trim((string) ($text['q'] ?? '')) === '') {
                $problems[] = "the {$language} question is empty";
            }

            if (count($options) !== 4 || in_array('', $options, true)) {
                $problems[] = "the {$language} version needs four non-empty options";
            } elseif (count(array_unique($options)) !== 4) {
                $problems[] = "two {$language} options are the same";
            }
        }

        return $problems;
    }
}
