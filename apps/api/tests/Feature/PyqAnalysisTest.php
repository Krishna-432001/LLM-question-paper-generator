<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pyq\PyqAnalyzer;
use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\PreviousYearPaper;
use App\Models\PreviousYearQuestion;
use App\Models\TopicFrequency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PYQ analysis is the only evidence the platform has about what an exam
 * actually values, and it feeds straight into which questions get selected for
 * a paper. A silent error here would quietly skew every paper produced, so the
 * scoring rules are pinned rather than left to inspection.
 */
final class PyqAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Exam $exam;

    private CanonicalTopic $polity;

    private CanonicalTopic $ancientHistory;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::withoutTenancy(function (): void {
            $this->exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'PYQ_EXAM',
                'name' => 'PYQ Exam', 'is_active' => true,
            ]);

            $this->polity = CanonicalTopic::query()->create([
                'slug' => 'indian-polity', 'name' => 'Indian Polity',
                'domain' => 'polity', 'path' => 'indian-polity', 'depth' => 0,
            ]);

            $this->ancientHistory = CanonicalTopic::query()->create([
                'slug' => 'ancient-history', 'name' => 'Ancient History',
                'domain' => 'history', 'path' => 'ancient-history', 'depth' => 0,
            ]);
        });
    }

    public function test_a_topic_asked_often_and_recently_outranks_one_that_went_quiet(): void
    {
        TenantContext::withoutTenancy(function (): void {
            // Polity: present every year, heavily.
            foreach ([2019, 2020, 2021, 2022, 2023] as $year) {
                $this->paperWith($year, $this->polity, 8);
            }

            // Ancient history: asked more in total, but nothing since 2020.
            // A raw count would rank it first; a candidate cramming from it
            // would be studying the wrong thing.
            $this->paperWith(2019, $this->ancientHistory, 20, append: true);
            $this->paperWith(2020, $this->ancientHistory, 21, append: true);
        });

        $result = app(PyqAnalyzer::class)->rebuild($this->exam->getKey());

        $this->assertSame(2, $result['topics']);
        $this->assertSame(5, $result['years']);

        $weights = app(PyqAnalyzer::class)->weightsFor($this->exam->getKey());

        $this->assertGreaterThan(
            $weights[$this->ancientHistory->getKey()],
            $weights[$this->polity->getKey()],
            'Recency must outweigh a larger but stale question count.',
        );

        // Bounded, because SelectionEngine multiplies this against quality and
        // syllabus weight. An unbounded score would swamp both.
        foreach ($weights as $weight) {
            $this->assertGreaterThanOrEqual(0.0, $weight);
            $this->assertLessThanOrEqual(1.0, $weight);
        }
    }

    public function test_it_records_when_a_topic_was_first_and_last_asked(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->paperWith(2018, $this->polity, 3);
            $this->paperWith(2023, $this->polity, 4, append: true);
        });

        app(PyqAnalyzer::class)->rebuild($this->exam->getKey());

        $row = TopicFrequency::query()
            ->where('canonical_topic_id', $this->polity->getKey())
            ->firstOrFail();

        $this->assertSame(7, $row->total_asked);
        $this->assertSame(2, $row->years_asked);
        $this->assertSame(2018, $row->first_asked_year);
        $this->assertSame(2023, $row->last_asked_year);
    }

    public function test_a_topic_that_stopped_appearing_is_marked_dormant(): void
    {
        TenantContext::withoutTenancy(function (): void {
            foreach ([2018, 2019, 2020, 2021, 2022, 2023] as $year) {
                $this->paperWith($year, $this->polity, 5);
            }

            // Present only in the earlier half of the window.
            $this->paperWith(2018, $this->ancientHistory, 6, append: true);
            $this->paperWith(2019, $this->ancientHistory, 6, append: true);
        });

        app(PyqAnalyzer::class)->rebuild($this->exam->getKey());

        $this->assertSame('dormant', TopicFrequency::query()
            ->where('canonical_topic_id', $this->ancientHistory->getKey())
            ->value('trend'));

        $this->assertSame('stable', TopicFrequency::query()
            ->where('canonical_topic_id', $this->polity->getKey())
            ->value('trend'));
    }

    public function test_it_refuses_to_call_two_papers_a_trend(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->paperWith(2022, $this->polity, 1);
            $this->paperWith(2023, $this->polity, 9, append: true);
        });

        app(PyqAnalyzer::class)->rebuild($this->exam->getKey());

        // A ninefold jump across two years is noise, not a trend, and calling
        // it "rising" would send a content team chasing it.
        $this->assertSame('stable', TopicFrequency::query()
            ->where('canonical_topic_id', $this->polity->getKey())
            ->value('trend'));
    }

    public function test_unmapped_questions_are_excluded_rather_than_guessed(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $paper = $this->paperWith(2023, $this->polity, 2);

            // A question the mapper could not place. Counting it against some
            // default topic would invent evidence that does not exist.
            PreviousYearQuestion::query()->create([
                'paper_id' => $paper->getKey(),
                'question_number' => 99,
                'question_text' => 'An unmapped question.',
                'canonical_topic_id' => null,
                'question_hash' => PreviousYearQuestion::hashFor('An unmapped question.'),
            ]);
        });

        $result = app(PyqAnalyzer::class)->rebuild($this->exam->getKey());

        $this->assertSame(2, $result['questions']);
        $this->assertSame(1, $result['topics']);
    }

    public function test_an_exam_with_no_papers_yields_no_weights(): void
    {
        $result = app(PyqAnalyzer::class)->rebuild($this->exam->getKey());

        $this->assertSame(['topics' => 0, 'questions' => 0, 'years' => 0], $result);
        $this->assertSame([], app(PyqAnalyzer::class)->weightsFor($this->exam->getKey()));
    }

    private function paperWith(
        int $year,
        CanonicalTopic $topic,
        int $count,
        bool $append = false,
    ): PreviousYearPaper {
        $paper = PreviousYearPaper::query()->firstOrCreate(
            ['exam_id' => $this->exam->getKey(), 'year' => $year, 'paper_name' => "Paper {$year}"],
            ['organization_id' => null, 'status' => 'parsed'],
        );

        $offset = $append
            ? (int) PreviousYearQuestion::query()->where('paper_id', $paper->getKey())->max('question_number')
            : 0;

        for ($i = 1; $i <= $count; $i++) {
            $stem = "Question {$topic->slug} {$year} number ".($offset + $i);

            PreviousYearQuestion::query()->create([
                'paper_id' => $paper->getKey(),
                'question_number' => $offset + $i,
                'question_text' => $stem,
                'canonical_topic_id' => $topic->getKey(),
                'difficulty' => 'medium',
                'question_type' => 'mcq4',
                'question_hash' => PreviousYearQuestion::hashFor($stem),
            ]);
        }

        return $paper;
    }
}
