<?php

declare(strict_types=1);

namespace App\Actions\PracticePapers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\PracticePapers\DTOs\PaperReading;
use App\Domain\PracticePapers\DTOs\PaperSettings;
use App\Domain\PracticePapers\PracticePaper;
use App\Domain\Pyq\DTOs\ParsedPyq;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSource;
use App\Models\QuestionTranslation;
use App\Models\Source;
use App\Models\Test;
use App\Models\TestQuestion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Parsed questions in, a sittable timed test out.
 *
 * The questions go into the bank like any other — options, answer, text per
 * language, citation — because that is what the runner, the scorer and the
 * solutions screen already read. Three things keep them from leaking anywhere
 * else:
 *
 *   - they belong to the uploader's organization, never the platform;
 *   - they are never mapped to an exam, and selection only ever draws from
 *     mapped questions, so no generated paper can pick one up;
 *   - the test keeps the paper's own order. It is not assembled by the
 *     selection engine: the point is to sit the paper that was printed.
 *
 * They are APPROVED without review because nobody wrote them here: they are
 * the uploader's own copy of a printed paper, and the database already allows
 * a previous_paper question to be approved without a source chunk.
 */
final class BuildPracticeTestAction
{
    /** Shown in full; past this the list stops being useful and is cut. */
    private const LISTED_NUMBERS = 20;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array<string, mixed>  the parse report, test_id included
     */
    public function execute(Source $source, PaperReading $reading, PaperSettings $settings): array
    {
        $questions = $reading->questions;
        $detected = $this->languagesIn($questions);
        $languageIds = Language::query()->whereIn('code', $detected)->pluck('id', 'code')->all();
        $testLanguage = $this->testLanguage($questions, $detected, $settings->language);

        if (! isset($languageIds[$testLanguage])) {
            throw new RuntimeException("Language '{$testLanguage}' is not set up.");
        }

        $keyed = count(array_filter($questions, static fn (ParsedPyq $q): bool => $q->correctOption !== null));
        $missing = $this->missingNumbers($questions);

        $report = [
            'parsed' => count($questions),
            // Reported rather than hidden. A paper of 100 questions that yields
            // 61 lost 39, and the student should know before trusting a score.
            'skipped' => count($missing),
            'keyed' => $keyed,
            'pages' => $reading->pages,
            'languages_detected' => $detected,
            'language' => $testLanguage,
            'ocr' => $reading->ocr,
            'warnings' => $this->warnings($reading, $questions, $missing, $keyed, $testLanguage),
        ];

        $duration = $settings->durationMinutes ?? max(1, count($questions));

        return DB::transaction(function () use (
            $source, $questions, $settings, $languageIds, $testLanguage, $report, $duration
        ): array {
            $test = Test::query()->create([
                'organization_id' => $settings->organizationId,
                'created_by' => $settings->userId,
                'exam_id' => $settings->examId,
                'type' => 'custom',
                'title' => $settings->title,
                'language_id' => $languageIds[$testLanguage],
                'question_count' => count($questions),
                'total_marks' => count($questions),
                'duration_minutes' => $duration,
                'negative_marking' => 0,
                'status' => 'ready',
                'selection_profile' => [
                    'kind' => PracticePaper::KIND,
                    'source_id' => $source->getKey(),
                    // The printed number of each question, in sequence order,
                    // so a key typed later as "7-B" finds question 7.
                    'question_numbers' => array_map(static fn (ParsedPyq $q): int => $q->number, $questions),
                    'report' => $report,
                ],
            ]);

            foreach ($questions as $i => $parsed) {
                $stored = $this->storeQuestion($parsed, $source, $settings, $languageIds, $testLanguage);

                TestQuestion::query()->create([
                    'test_id' => $test->getKey(),
                    'question_id' => $stored['question_id'],
                    'question_translation_id' => $stored['translation_id'],
                    'sequence' => $i + 1,
                    'marks' => 1,
                ]);
            }

            $this->audit->record('practice_paper.created', $test, [
                'title' => $test->title,
                'source_id' => $source->getKey(),
                'parsed' => $report['parsed'],
                'skipped' => $report['skipped'],
                'keyed' => $report['keyed'],
                'languages' => $report['languages_detected'],
                'ocr' => $report['ocr'],
                'duration_minutes' => $duration,
            ], $settings->userId);

            return [
                'test_id' => $test->getKey(),
                'title' => $test->title,
                'question_count' => count($questions),
                'duration_minutes' => $duration,
            ] + $report;
        });
    }

    /**
     * @param  array<string, string>  $languageIds  code => id
     * @return array{question_id: string, translation_id: string}
     */
    private function storeQuestion(
        ParsedPyq $parsed,
        Source $source,
        PaperSettings $settings,
        array $languageIds,
        string $testLanguage,
    ): array {
        $masterLanguageId = $languageIds[$parsed->language] ?? null;

        if ($masterLanguageId === null) {
            throw new RuntimeException("Language '{$parsed->language}' is not set up.");
        }

        $question = Question::query()->create([
            'organization_id' => $settings->organizationId,
            'format' => 'mcq4',
            'origin' => 'previous_paper',
            'status' => 'APPROVED',
            'master_language_id' => $masterLanguageId,
            // A printed paper carries no difficulty; medium claims nothing.
            'difficulty' => 'medium',
            'current_version' => 1,
            'created_by' => $settings->userId,
            'approved_by' => $settings->userId,
            'approved_at' => now(),
        ]);

        foreach (['A', 'B', 'C', 'D'] as $position => $key) {
            QuestionOption::query()->create([
                'question_id' => $question->getKey(),
                'option_key' => $key,
                'position' => $position + 1,
                // No key printed means no option is marked correct, which the
                // scorer reads as "unscored", never as "wrong".
                'is_correct' => $parsed->correctOption === $key,
            ]);
        }

        $translationIds = [];

        foreach ($parsed->translations as $code => $version) {
            if (! isset($languageIds[$code])) {
                continue;
            }

            $isMaster = $code === $parsed->language;

            $translationIds[$code] = QuestionTranslation::query()->create([
                'question_id' => $question->getKey(),
                'language_id' => $languageIds[$code],
                'is_master' => $isMaster,
                'source_language_id' => $isMaster ? null : $masterLanguageId,
                'question_text' => $version['stem'],
                'option_a' => $version['options']['A'] ?? null,
                'option_b' => $version['options']['B'] ?? null,
                'option_c' => $version['options']['C'] ?? null,
                'option_d' => $version['options']['D'] ?? null,
                // Printed by the examining body, not machine-translated, so
                // the second language is as trustworthy as the first.
                'translation_status' => 'HUMAN_APPROVED',
            ])->getKey();
        }

        QuestionSource::query()->create([
            'question_id' => $question->getKey(),
            'source_id' => $source->getKey(),
            'is_primary' => true,
            // The citation a solution shows: which paper, which question.
            'breadcrumb' => "{$settings->title} — Q{$parsed->number}",
        ]);

        return [
            'question_id' => $question->getKey(),
            'translation_id' => $translationIds[$testLanguage] ?? $translationIds[$parsed->language],
        ];
    }

    /**
     * Numbers the paper must have printed but the parser could not read.
     *
     * Counted from 1, unless the paper starts well past it: a PDF holding
     * questions 101-200 is the second part of a longer paper, not one that
     * lost a hundred questions.
     *
     * @param  list<ParsedPyq>  $questions
     * @return list<int>
     */
    private function missingNumbers(array $questions): array
    {
        if ($questions === []) {
            return [];
        }

        $numbers = array_map(static fn (ParsedPyq $q): int => $q->number, $questions);
        $lowest = min($numbers);

        return array_values(array_diff(range($lowest <= 5 ? 1 : $lowest, max($numbers)), $numbers));
    }

    /**
     * @param  list<ParsedPyq>  $questions
     * @return list<string>  every language any question was printed in, in order of first appearance
     */
    private function languagesIn(array $questions): array
    {
        $seen = [];

        foreach ($questions as $question) {
            foreach ($question->languages() as $code) {
                $seen[$code] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * The student's own language when the paper was printed in it, otherwise
     * whichever language most questions were printed in first. A question not
     * printed in the test's language falls back to its own master text.
     *
     * @param  list<ParsedPyq>  $questions
     * @param  list<string>  $detected
     */
    private function testLanguage(array $questions, array $detected, ?string $preferred): string
    {
        if ($preferred !== null && in_array($preferred, $detected, true)) {
            return $preferred;
        }

        $counts = array_count_values(array_map(static fn (ParsedPyq $q): string => $q->language, $questions));
        arsort($counts);

        return (string) (array_key_first($counts) ?? 'en');
    }

    /**
     * @param  list<ParsedPyq>  $questions
     * @param  list<int>  $missing
     * @return list<string>
     */
    private function warnings(PaperReading $reading, array $questions, array $missing, int $keyed, string $testLanguage): array
    {
        $warnings = $reading->warnings;

        if ($missing !== []) {
            $listed = implode(', ', array_slice($missing, 0, self::LISTED_NUMBERS))
                .(count($missing) > self::LISTED_NUMBERS ? ', …' : '');

            $warnings[] = count($missing).' question'.(count($missing) === 1 ? '' : 's')
                ." could not be read and {$this->were(count($missing))} left out (numbers {$listed}). "
                .'A question is skipped rather than guessed when its four options cannot all be found.';
        }

        if ($keyed === 0) {
            $warnings[] = 'No answer key was found in the PDF. You can sit the test now, but answers will not be '
                .'scored until you add the key (for example "1-A 2-C 3-B").';
        } elseif ($keyed < count($questions)) {
            $unkeyed = count($questions) - $keyed;
            $warnings[] = "The answer key covers {$keyed} of ".count($questions).' questions; the other '
                ."{$unkeyed} will not be scored until you add their answers.";
        }

        $notInLanguage = array_values(array_map(
            static fn (ParsedPyq $q): int => $q->number,
            array_filter($questions, static fn (ParsedPyq $q): bool => ! isset($q->translations[$testLanguage])),
        ));

        if ($notInLanguage !== [] && count($notInLanguage) < count($questions)) {
            $warnings[] = count($notInLanguage).' question'.(count($notInLanguage) === 1 ? ' was' : 's were')
                .' not printed in '.($testLanguage === 'ta' ? 'Tamil' : 'English')
                .' (or could not be read in it) and will show in the language they were printed in.';
        }

        return $warnings;
    }

    private function were(int $count): string
    {
        return $count === 1 ? 'was' : 'were';
    }
}
