<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Documents\DocumentComposer;
use App\Domain\Tenancy\TenantContext;
use App\Models\Language;
use App\Models\Question;
use App\Models\Test;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TnpscBlueprintSeeder;
use Database\Seeders\TnpscGroup4PatternSeeder;
use Database\Seeders\TnpscGroup4Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * tests:import-model-papers — a ready-made 200-question paper becomes a shared,
 * timed test in the TNPSC Group IV pattern, in its printed order.
 */
final class ImportModelPapersTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private const PART_A = 'Part A — Tamil Eligibility-cum-Scoring Test';

    private const TITLE = 'TNPSC Group 4 · 2026 Prediction Model Paper 1';

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(TnpscGroup4Seeder::class);
        $this->seed(TnpscBlueprintSeeder::class);
        $this->seed(TnpscGroup4PatternSeeder::class);

        $this->student = $this->makeAdmin('model-papers@example.test', 'student', platformAdmin: false);
    }

    /** One question as render_papers.py writes it: Part A in Tamil alone, the rest English with Tamil. */
    private function question(int $num): array
    {
        $section = match (true) {
            $num <= 100 => self::PART_A,
            $num <= 175 => 'Part B — General Studies',
            default => 'Part B — Aptitude and Mental Ability',
        };
        $tamil = ['q' => "தமிழ் வினா {$num}?", 'options' => ["அ{$num}", "ஆ{$num}", "இ{$num}", "ஈ{$num}"]];

        return [
            'num' => $num,
            'section' => $section,
            'topic' => $num <= 100 ? 'modern-tamil-poets' : 'constitution-of-india',
            'format' => $num % 10 === 0 ? 'assertion_reason' : 'mcq4',
            'difficulty' => 'medium',
            'answer' => ['A', 'B', 'C', 'D'][$num % 4],
            'en' => $num <= 100 ? null : ['q' => "English question {$num}?", 'options' => ["a{$num}", "b{$num}", "c{$num}", "d{$num}"]],
            'ta' => $tamil,
            'explanation' => "Why {$num}.\n\nSource: Std 10 Social Science, p.{$num}",
        ];
    }

    /** @param  callable(list<array<string, mixed>>): list<array<string, mixed>>|null  $edit */
    private function file(?callable $edit = null): string
    {
        $questions = array_map(fn (int $n): array => $this->question($n), range(1, 200));
        $path = tempnam(sys_get_temp_dir(), 'ef-model-papers-');
        file_put_contents($path, json_encode([
            'exam' => 'TNPSC_G4',
            'papers' => [['title' => self::TITLE, 'questions' => $edit ? $edit($questions) : $questions]],
        ], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    private function import(string $file, string ...$options): PendingCommand
    {
        $arguments = ['file' => $file, '--user' => [$this->student->email]];

        foreach ($options as $option) {
            $arguments[$option] = true;
        }

        return $this->artisan('tests:import-model-papers', $arguments);
    }

    public function test_a_paper_becomes_a_shared_timed_test_in_the_exam_pattern(): void
    {
        $this->import($this->file())->expectsOutputToContain('given to model-papers@example.test')->assertSuccessful();

        $test = TenantContext::withoutTenancy(fn (): Test => Test::query()->where('title', self::TITLE)->sole());

        $this->assertSame(['prediction', 'ready', 200, 180, true], [$test->type, $test->status, $test->question_count, $test->duration_minutes, $test->is_shared]);
        $this->assertEquals(300, $test->total_marks);
        $this->assertNotNull($test->blueprint_id, 'The blueprint is what applies the Part A qualifying mark.');

        TenantContext::withoutTenancy(function () use ($test): void {
            $ids = DB::table('test_questions')->where('test_id', $test->getKey())->pluck('question_id');
            $this->assertSame(200, Question::query()->whereIn('id', $ids)->where('status', 'APPROVED')->where('origin', 'human_authored')->count());
            $this->assertFalse(
                DB::table('question_exam_mappings')->whereIn('question_id', $ids)->exists(),
                'Kept out of the bank, so no other paper deals these questions out.',
            );
            $this->assertSame(100, DB::table('test_questions')->where('test_id', $test->getKey())
                ->where('fixed_language_id', Language::byCode('ta')->getKey())->count());
        });

        $this->actingAs($this->student)->getJson('/api/v1/me/tests')->assertOk()->assertJsonFragment(['title' => self::TITLE]);

        $paper = $this->actingAs($this->student)->postJson("/api/v1/tests/{$test->getKey()}/attempt")->assertCreated()->json('data');
        $bySequence = collect($paper['questions'])->keyBy('sequence');

        $this->assertSame(['ta'], array_keys($bySequence[1]['texts']), 'Part A is set in Tamil alone.');
        $this->assertSame('தமிழ் வினா 1?', $bySequence[1]['question_text']);
        $this->assertSame(['en', 'ta'], array_keys($bySequence[101]['texts']));
        $this->assertSame('English question 101?', $bySequence[101]['question_text']);
        $this->assertSame('Part B — Aptitude and Mental Ability', $bySequence[200]['section']);

        // Thirty right in Part A (45 of 150) misses its 40%; everything else right.
        $answers = collect($paper['questions'])->map(fn (array $q): array => [
            'test_question_id' => $q['test_question_id'],
            'selected_option' => $q['sequence'] <= 100 && $q['sequence'] > 30
                ? ['B', 'C', 'D', 'A'][$q['sequence'] % 4]
                : ['A', 'B', 'C', 'D'][$q['sequence'] % 4],
        ])->all();

        $result = $this->actingAs($this->student)
            ->postJson("/api/v1/attempts/{$paper['attempt_id']}/submit", ['answers' => $answers])
            ->assertOk()
            ->json('data');

        [$partA, $general, $aptitude] = $result['section_breakdown'];
        $this->assertSame([self::PART_A, 30, 45], [$partA['name'], $partA['correct'], (int) $partA['marks']]);
        $this->assertFalse($partA['qualified']);
        $this->assertSame([75, 25], [$general['correct'], $aptitude['correct']]);
    }

    public function test_the_printed_paper_sets_part_a_in_tamil_and_the_rest_in_both_languages(): void
    {
        $this->import($this->file())->assertSuccessful();

        $html = TenantContext::withoutTenancy(fn () => app(DocumentComposer::class)
            ->questionPaper(Test::query()->where('title', self::TITLE)->sole(), 'MODEL-1'))['html'];

        $this->assertSame(1, substr_count($html, '<div class="part">'.self::PART_A.'</div>'));
        $this->assertStringContainsString('தமிழ் வினா 1?', $html);
        $this->assertStringNotContainsString('English question 1?', $html);
        $this->assertStringContainsString('English question 101?', $html);
        $this->assertStringContainsString('தமிழ் வினா 101?', $html);
    }

    public function test_loading_again_skips_the_user_and_a_second_user_shares_the_questions(): void
    {
        $file = $this->file();
        $other = $this->makeAdmin('second@example.test', 'student', platformAdmin: false);

        $this->import($file)->assertSuccessful();
        $this->import($file)->expectsOutputToContain('already has it')->assertSuccessful();
        $this->artisan('tests:import-model-papers', ['file' => $file, '--user' => [$other->email]])->assertSuccessful();

        TenantContext::withoutTenancy(function (): void {
            $this->assertSame(2, Test::query()->where('title', self::TITLE)->count());
            $this->assertSame(200, Question::query()->where('origin', 'human_authored')->count(), 'The second copy reuses the questions.');
        });
    }

    public function test_replacing_swaps_an_unused_copy_and_refuses_one_already_attempted(): void
    {
        $this->import($this->file())->assertSuccessful();
        $edited = $this->file(function (array $questions): array {
            $questions[100]['en']['q'] = 'Corrected question 101?';

            return $questions;
        });

        $this->import($edited, '--replace')->expectsOutputToContain('1 loaded copies removed')->assertSuccessful();

        $test = TenantContext::withoutTenancy(fn (): Test => Test::query()->where('title', self::TITLE)->sole());
        TenantContext::withoutTenancy(fn () => $this->assertSame(200, Question::query()->where('origin', 'human_authored')->count()));

        $paper = $this->actingAs($this->student)->postJson("/api/v1/tests/{$test->getKey()}/attempt")->assertCreated()->json('data');
        $this->assertSame('Corrected question 101?', collect($paper['questions'])->firstWhere('sequence', 101)['question_text']);

        $this->import($edited, '--replace')->expectsOutputToContain('already been attempted')->assertFailed();
        TenantContext::withoutTenancy(fn () => $this->assertTrue(Test::query()->whereKey($test->getKey())->exists()));
    }

    public function test_a_paper_that_breaks_the_pattern_is_refused_whole(): void
    {
        $short = $this->file(fn (array $questions): array => array_slice($questions, 0, 199));

        $this->import($short)
            ->expectsOutputToContain('part "Part B — Aptitude and Mental Ability" has 24 questions; the pattern sets 25')
            ->assertFailed();

        $untranslated = $this->file(function (array $questions): array {
            unset($questions[4]['ta']);

            return $questions;
        });

        $this->import($untranslated)->expectsOutputToContain('question 5: its part is set in Tamil but it has no Tamil text')->assertFailed();
        TenantContext::withoutTenancy(fn () => $this->assertSame(0, Test::query()->count()));
    }
}
