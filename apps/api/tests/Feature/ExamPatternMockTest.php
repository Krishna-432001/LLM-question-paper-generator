<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Documents\DocumentComposer;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionTranslation;
use App\Models\Source;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * The full mock in the real TNPSC Group IV pattern: Part A Tamil (100),
 * General Studies (75), Aptitude (25); 1.5 marks each; 180 minutes; each
 * unit its official share; Part A in Tamil whatever the paper's language.
 */
final class ExamPatternMockTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    /** Official questions per paper, by a fragment of the unit's name. */
    private const UNITS = [
        'Tamil Grammar' => 33, 'Tamil Literature' => 33, 'Tamil Scholars' => 34,
        'General Science' => 5, 'Geography' => 5, 'Culture of India' => 10, 'Indian Polity' => 15,
        'Development Administration' => 20, 'Socio-Political Movements' => 20,
        'Aptitude' => 25,
    ];

    private const PART_A = 'Part A — Tamil Eligibility-cum-Scoring Test';

    private User $student;

    private Exam $exam;

    private Source $source;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $this->seed(\Database\Seeders\TnpscBlueprintSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4PatternSeeder::class);

        $this->student = $this->makeAdmin('mock-student@example.test', 'student', platformAdmin: false);
        $this->exam = TenantContext::withoutTenancy(fn (): Exam => Exam::query()->where('code', 'TNPSC_G4')->firstOrFail());
        $this->source = $this->makeSource('Std 6-10 books');
    }

    // ------------------------------------------------------------- fixtures

    /** A canonical topic filed under this unit and no other, so counts per unit are exact. */
    private function topicOf(string $unitFragment): string
    {
        return TenantContext::withoutTenancy(function () use ($unitFragment): string {
            $unitId = DB::table('exam_units')->where('name', 'like', "%{$unitFragment}%")->value('id');
            $elsewhere = DB::table('exam_topics')->where('exam_unit_id', '!=', $unitId)->whereNotNull('canonical_topic_id')->pluck('canonical_topic_id');

            return (string) DB::table('exam_topics')
                ->where('exam_unit_id', $unitId)
                ->whereNotNull('canonical_topic_id')
                ->whereNotIn('canonical_topic_id', $elsewhere)
                ->value('canonical_topic_id');
        });
    }

    /** Approved questions in one unit: English, or Tamil as their only language. */
    private function fill(string $unitFragment, int $count, string $language = 'en'): void
    {
        $topic = $this->topicOf($unitFragment);

        for ($i = 0; $i < $count; $i++) {
            $n = $this->sequence++;
            $question = $this->makeQuestion($n, 'APPROVED', $this->source, $this->exam, ['canonical_topic_id' => $topic]);

            if ($language === 'ta') {
                TenantContext::withoutTenancy(function () use ($question, $n): void {
                    $tamil = Language::byCode('ta')->getKey();
                    $question->forceFill(['master_language_id' => $tamil])->save();
                    QuestionTranslation::query()->where('question_id', $question->getKey())->update([
                        'language_id' => $tamil,
                        // ASCII markers keep each stem distinct to the diversity filter.
                        'question_text' => "பின்வருவனவற்றுள் சரியான இலக்கண வடிவம் எது? kural{$n} paadal".($n * 7),
                        'option_a' => "முதல் விடை opta{$n}", 'option_b' => "இரண்டாம் விடை optb{$n}",
                        'option_c' => "மூன்றாம் விடை optc{$n}", 'option_d' => "நான்காம் விடை optd{$n}",
                    ]);
                });
            }
        }
    }

    /** Every unit filled to its official share, Part A in Tamil. */
    private function fillWholePattern(): void
    {
        foreach (self::UNITS as $unit => $count) {
            $this->fill($unit, $count, str_starts_with($unit, 'Tamil') ? 'ta' : 'en');
        }
    }

    /** @param  array<string, mixed>  $fields */
    private function requestMock(array $fields = []): TestResponse
    {
        return $this->actingAs($this->student)->postJson('/api/v1/tests/exam-pattern', $fields + [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
        ]);
    }

    /** @return array<string, int> unit fragment => questions of that unit on the paper */
    private function unitCounts(string $testId): array
    {
        $counts = [];

        foreach (array_keys(self::UNITS) as $unit) {
            $counts[$unit] = TenantContext::withoutTenancy(fn (): int => DB::table('test_questions as tq')
                ->join('questions as q', 'q.id', '=', 'tq.question_id')
                ->where('tq.test_id', $testId)
                ->where('q.canonical_topic_id', $this->topicOf($unit))
                ->count());
        }

        return $counts;
    }

    // ---------------------------------------------------------------- tests

    public function test_a_full_mock_follows_the_exam_pattern_part_by_part_and_unit_by_unit(): void
    {
        $this->fillWholePattern();
        // Extra Polity questions the pattern must not take: it asks for 15.
        $this->fill('Indian Polity', 10);

        $data = $this->requestMock()->assertCreated()->json('data');

        $this->assertSame(200, $data['question_count']);
        $this->assertEquals(300, $data['total_marks']);
        $this->assertSame(180, $data['duration_minutes']);
        $this->assertSame('model', $data['type']);
        $this->assertEquals([
            ['name' => self::PART_A, 'questions' => 100, 'marks' => 150.0],
            ['name' => 'Part B — General Studies', 'questions' => 75, 'marks' => 112.5],
            ['name' => 'Part B — Aptitude and Mental Ability', 'questions' => 25, 'marks' => 37.5],
        ], $data['sections']);

        $this->assertSame(self::UNITS, $this->unitCounts($data['id']), 'Each unit gets its official share.');
    }

    public function test_part_a_is_set_in_tamil_even_on_an_english_paper(): void
    {
        $this->fill('Tamil Grammar', 3, 'ta');
        $this->fill('Indian Polity', 4);

        $testId = $this->requestMock(['allow_short' => true])->assertCreated()->json('data.id');
        $questions = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")->assertCreated()->json('data.questions');

        $partA = array_values(array_filter($questions, static fn (array $q): bool => $q['section'] === self::PART_A));
        $partB = array_values(array_filter($questions, static fn (array $q): bool => $q['section'] === 'Part B — General Studies'));

        $this->assertCount(3, $partA);
        $this->assertCount(4, $partB);
        $this->assertSame(1, $partA[0]['sequence'], 'Part A comes first, as on the real paper.');
        $this->assertStringContainsString('இலக்கண', $partA[0]['question_text']);
        $this->assertSame(['ta'], array_keys($partA[0]['texts']), 'Tamil alone — not "unavailable in English".');
        $this->assertStringStartsWith('Which', $partB[0]['question_text']);
        $this->assertSame(['en'], array_keys($partB[0]['texts']));
    }

    public function test_a_bank_that_cannot_fill_the_pattern_is_refused_with_the_count_for_each_part(): void
    {
        $this->fill('Indian Polity', 6);
        $this->fill('Aptitude', 4);

        $response = $this->requestMock()
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_pattern_short')
            ->assertJsonPath('meta.available_total', 10);

        $sections = $response->json('meta.sections');
        $this->assertSame([self::PART_A, 0, 100], [$sections[0]['name'], $sections[0]['available'], $sections[0]['needed']]);
        $this->assertSame([6, 75], [$sections[1]['available'], $sections[1]['needed']]);
        $this->assertSame([4, 25], [$sections[2]['available'], $sections[2]['needed']]);
        $polity = collect($sections[1]['units'])->first(static fn (array $u): bool => str_contains($u['name'], 'Indian Polity'));
        $this->assertSame([6, 15], [$polity['available'], $polity['needed']]);

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Test::query()->count()), 'Nothing is built until asked.');

        // Asked for: the shorter mock, its time scaled to the real paper's pace.
        $data = $this->requestMock(['allow_short' => true])->assertCreated()->json('data');

        $this->assertSame(10, $data['question_count']);
        $this->assertSame(9, $data['duration_minutes'], '180 minutes for 200 questions is 9 for 10.');
        $this->assertStringContainsString('(10 of 200 questions)', $data['title']);
    }

    public function test_an_empty_bank_is_refused_even_when_a_shorter_mock_is_allowed(): void
    {
        $this->requestMock(['allow_short' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'exam_pattern_short')
            ->assertJsonPath('meta.available_total', 0);
    }

    public function test_each_part_is_scored_and_part_a_against_its_qualifying_mark(): void
    {
        $this->fill('Tamil Grammar', 4, 'ta');
        $this->fill('Indian Polity', 2);

        $testId = $this->requestMock(['allow_short' => true])->assertCreated()->json('data.id');
        $attempt = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")->assertCreated()->json('data');

        $keys = TenantContext::withoutTenancy(fn () => Test::query()->with('questions.question.options')->findOrFail($testId)
            ->questions->mapWithKeys(static fn ($tq) => [$tq->getKey() => $tq->question->correctOptionKey()]));

        // One Part A answer right of four (1.5 of 6 marks, under the 40% = 2.4
        // needed); both Part B answers right.
        $answers = [];
        $partARight = 0;

        foreach ($attempt['questions'] as $q) {
            $key = $keys[$q['test_question_id']];
            $right = $q['section'] !== self::PART_A || $partARight++ === 0;
            $answers[] = [
                'test_question_id' => $q['test_question_id'],
                'selected_option' => $right ? $key : ($key === 'A' ? 'B' : 'A'),
            ];
        }

        $result = $this->actingAs($this->student)
            ->postJson("/api/v1/attempts/{$attempt['attempt_id']}/submit", ['answers' => $answers])
            ->assertOk()
            ->json('data');

        $this->assertEquals(4.5, $result['final_score'], 'Three right at 1.5 marks each.');
        [$partA, $partB] = $result['section_breakdown'];

        $this->assertSame(self::PART_A, $partA['name']);
        $this->assertEquals(1.5, $partA['marks']);
        $this->assertEquals(6, $partA['max_marks']);
        $this->assertEquals(2.4, $partA['qualifying_marks']);
        $this->assertFalse($partA['qualified']);
        $this->assertSame(['Part B — General Studies', 2, 2], [$partB['name'], $partB['correct'], $partB['questions']]);
        $this->assertNull($partB['qualified'], 'Part B has no qualifying mark of its own.');

        $sections = $this->actingAs($this->student)->getJson("/api/v1/attempts/{$attempt['attempt_id']}")
            ->assertOk()
            ->json('data.result.sections');
        $this->assertFalse($sections[0]['qualified']);
    }

    public function test_the_printed_paper_names_each_part_where_it_begins(): void
    {
        $this->fill('Tamil Grammar', 2, 'ta');
        $this->fill('Indian Polity', 2);

        $testId = $this->requestMock(['allow_short' => true])->assertCreated()->json('data.id');

        $paper = TenantContext::withoutTenancy(fn () => app(DocumentComposer::class)
            ->questionPaper(Test::query()->findOrFail($testId), 'MOCK-1'));

        $this->assertSame(1, substr_count($paper['html'], '<div class="part">'.self::PART_A.'</div>'));
        $this->assertSame(1, substr_count($paper['html'], '<div class="part">Part B — General Studies</div>'));
        $this->assertStringContainsString('இலக்கண', $paper['html'], 'Part A prints in Tamil on an English paper.');
        $this->assertSame(self::PART_A, $paper['payload']['questions'][0]['section']);
    }
}
