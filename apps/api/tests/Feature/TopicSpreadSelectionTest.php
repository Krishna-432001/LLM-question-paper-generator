<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Tests\AssembleTestAction;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionTranslation;
use App\Models\Source;
use App\Models\StudyPlan;
use App\Models\StudyPlanDay;
use App\Models\StudyPlanItem;
use App\Models\Test;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TnpscBlueprintSeeder;
use Database\Seeders\TnpscGroup4PatternSeeder;
use Database\Seeders\TnpscGroup4Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * A paper is divided over its topics before questions are ranked, every
 * topic is sampled however large another topic is, an English plan test sets
 * Part A in Tamil as the exam does, and a short paper says it is short.
 */
final class TopicSpreadSelectionTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $student;

    private Source $source;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->student = $this->makeAdmin('spread-student@example.test', 'student', platformAdmin: false);
        $this->source = $this->makeSource('Spread fixtures');
    }

    // ------------------------------------------------------------- fixtures

    /** Approved English questions on one topic, mapped to the exam. */
    private function questionsOn(Exam $exam, string $topicId, int $count, ?float $quality = null): void
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->makeQuestion($this->sequence++, 'APPROVED', $this->source, $exam, ['canonical_topic_id' => $topicId])->getKey();
        }

        if ($quality !== null) {
            TenantContext::withoutTenancy(fn () => Question::query()->whereIn('id', $ids)->update(['quality_score' => $quality]));
        }
    }

    /**
     * Many approved questions on one topic in a few statements: enough to
     * outnumber any fixed-size sample of the bank.
     */
    private function bulkQuestionsOn(Exam $exam, string $topicId, int $count): void
    {
        TenantContext::withoutTenancy(function () use ($exam, $topicId, $count): void {
            $english = Language::byCode('en')->getKey();

            DB::statement(
                "INSERT INTO questions (master_language_id, difficulty, status, origin, quality_score, canonical_topic_id)
                 SELECT ?, (ARRAY['easy','medium','hard'])[1 + g % 3]::difficulty_level, 'APPROVED', 'ai_generated', 95, ?
                 FROM generate_series(1, ?) AS g",
                [$english, $topicId, $count],
            );
            DB::statement(
                "INSERT INTO question_translations (question_id, language_id, is_master, question_text, option_a, option_b, option_c, option_d, translation_status)
                 SELECT q.id, ?, true,
                        'Compute the bulk quantity for token' || replace(q.id::text, '-', '') || ' using method variant' || (abs(hashtext(q.id::text)) % 97),
                        'first' || left(md5(q.id::text), 6), 'second' || left(md5(q.id::text), 7),
                        'third' || left(md5(q.id::text), 8), 'fourth' || left(md5(q.id::text), 9),
                        'HUMAN_APPROVED'
                 FROM questions q WHERE q.canonical_topic_id = ?",
                [$english, $topicId],
            );
            DB::statement(
                'INSERT INTO question_exam_mappings (question_id, exam_id, is_active)
                 SELECT q.id, ?, true FROM questions q WHERE q.canonical_topic_id = ?',
                [$exam->getKey(), $topicId],
            );
        });
    }

    /**
     * @param  list<string>  $topics
     * @param  array<string, mixed>  $extra
     */
    private function assemble(Exam $exam, array $topics, int $count, array $extra = []): Test
    {
        return TenantContext::withoutTenancy(fn (): Test => app(AssembleTestAction::class)->execute($extra + [
            'organization_id' => $this->organizationId(),
            'user_id' => $this->student->getKey(),
            'exam_id' => $exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
            'type' => 'weekly',
            'question_count' => $count,
            'canonical_topic_ids' => $topics,
        ]));
    }

    /** @return array<string, int> canonical topic id => questions of it on the paper */
    private function topicCounts(Test $test): array
    {
        return TenantContext::withoutTenancy(fn (): array => DB::table('test_questions as tq')
            ->join('questions as q', 'q.id', '=', 'tq.question_id')
            ->where('tq.test_id', $test->getKey())
            ->groupBy('q.canonical_topic_id')
            ->selectRaw('q.canonical_topic_id AS topic, count(*) AS n')
            ->pluck('n', 'topic')
            ->map(static fn ($n): int => (int) $n)
            ->all());
    }

    private function organizationId(): string
    {
        return (string) TenantContext::withoutTenancy(fn () => OrganizationUser::query()
            ->where('user_id', $this->student->getKey())
            ->value('organization_id'));
    }

    /** A canonical topic filed under this TNPSC unit and no other. */
    private function tnpscTopic(string $unitFragment): object
    {
        return TenantContext::withoutTenancy(function () use ($unitFragment): object {
            $unitId = DB::table('exam_units')->where('name', 'like', "%{$unitFragment}%")->value('id');
            $elsewhere = DB::table('exam_topics')->where('exam_unit_id', '!=', $unitId)->whereNotNull('canonical_topic_id')->pluck('canonical_topic_id');

            return DB::table('exam_topics')
                ->where('exam_unit_id', $unitId)
                ->whereNotNull('canonical_topic_id')
                ->whereNotIn('canonical_topic_id', $elsewhere)
                ->orderBy('sequence')
                ->select('id as exam_topic_id', 'canonical_topic_id as canonical', 'syllabus_version_id')
                ->first();
        });
    }

    private function seedTnpsc(): Exam
    {
        $this->seed(TnpscGroup4Seeder::class);
        $this->seed(TnpscBlueprintSeeder::class);
        $this->seed(TnpscGroup4PatternSeeder::class);

        return TenantContext::withoutTenancy(fn (): Exam => Exam::query()->where('code', 'TNPSC_G4')->firstOrFail());
    }

    // ---------------------------------------------------------------- tests

    public function test_a_multi_topic_paper_gives_no_topic_much_more_than_its_share(): void
    {
        $exam = $this->makeExam();
        $big = $this->makeTopic('big-topic', 'Big Topic')->getKey();
        $small = [$this->makeTopic('small-one', 'Small One')->getKey(), $this->makeTopic('small-two', 'Small Two')->getKey()];

        // The big topic is also the best-scored one: ranking the whole pool
        // and taking the top 30 would give it the entire paper.
        $this->questionsOn($exam, $big, 60, quality: 99);
        $this->questionsOn($exam, $small[0], 15, quality: 55);
        $this->questionsOn($exam, $small[1], 15, quality: 55);

        $test = $this->assemble($exam, [$big, ...$small], 30);
        $counts = $this->topicCounts($test);

        $this->assertSame(30, $test->question_count);
        $this->assertCount(3, $counts, 'Every topic is on the paper.');

        // Equal shares here (no syllabus weights): 10 each.
        foreach ($counts as $topic => $n) {
            $this->assertLessThanOrEqual(20, $n, "No topic takes more than twice its fair share ({$topic}).");
            $this->assertGreaterThanOrEqual(5, $n, "No topic is starved ({$topic}).");
        }
    }

    public function test_every_topic_in_scope_gets_a_question_when_the_count_allows(): void
    {
        $exam = $this->makeExam();
        $topics = [];

        for ($t = 0; $t < 12; $t++) {
            $topics[] = $this->makeTopic("topic-{$t}", "Topic {$t}")->getKey();
        }

        $this->questionsOn($exam, $topics[0], 40, quality: 99);

        foreach (array_slice($topics, 1) as $topic) {
            $this->questionsOn($exam, $topic, 3, quality: 50);
        }

        $test = $this->assemble($exam, $topics, 15);
        $counts = $this->topicCounts($test);

        $this->assertSame(15, $test->question_count);
        $this->assertEqualsCanonicalizing($topics, array_keys($counts), 'Fifteen questions over twelve topics reach all twelve.');
        $this->assertLessThanOrEqual(4, $counts[$topics[0]], 'The best-stocked topic does not soak up the rest.');
    }

    public function test_the_pool_reaches_every_topic_beside_a_topic_of_thousands(): void
    {
        $exam = $this->makeExam();
        $huge = $this->makeTopic('huge-topic', 'Huge Topic')->getKey();
        $small = [$this->makeTopic('tiny-one', 'Tiny One')->getKey(), $this->makeTopic('tiny-two', 'Tiny Two')->getKey()];

        // Inserted first and in bulk: an unordered fixed-size sample of the
        // bank (it was LIMIT 2000) holds only these.
        $this->bulkQuestionsOn($exam, $huge, 2_100);
        $this->questionsOn($exam, $small[0], 5);
        $this->questionsOn($exam, $small[1], 5);

        // Whole bank, no topic scope.
        $test = $this->assemble($exam, [], 12, ['canonical_topic_ids' => null]);
        $counts = $this->topicCounts($test);

        $this->assertSame(12, $test->question_count);
        $this->assertArrayHasKey($small[0], $counts);
        $this->assertArrayHasKey($small[1], $counts);
        $this->assertSame(4, $counts[$huge], 'Equal shares: four each, however many the big topic holds.');
    }

    public function test_an_english_plan_test_sets_part_a_topics_in_tamil(): void
    {
        $exam = $this->seedTnpsc();
        $grammar = $this->tnpscTopic('Tamil Grammar');
        $polity = $this->tnpscTopic('Indian Polity');
        $tamil = Language::byCode('ta')->getKey();
        $english = Language::byCode('en')->getKey();

        // Part A questions exist in Tamil alone, as in the real bank.
        $this->questionsOn($exam, $grammar->canonical, 6);
        TenantContext::withoutTenancy(function () use ($grammar, $tamil): void {
            foreach (Question::query()->where('canonical_topic_id', $grammar->canonical)->get() as $n => $question) {
                $question->forceFill(['master_language_id' => $tamil])->save();
                QuestionTranslation::query()->where('question_id', $question->getKey())->update([
                    'language_id' => $tamil,
                    'question_text' => "பின்வருவனவற்றுள் சரியான இலக்கண வடிவம் எது? kural{$n} paadal".($n * 7),
                    'option_a' => "முதல் opta{$n}", 'option_b' => "இரண்டாம் optb{$n}",
                    'option_c' => "மூன்றாம் optc{$n}", 'option_d' => "நான்காம் optd{$n}",
                ]);
            }
        });
        $this->questionsOn($exam, $polity->canonical, 10);

        // A weekly plan that studied both topics, with its test on day 7.
        [$plan, $testDay] = TenantContext::withoutTenancy(function () use ($exam, $grammar, $polity, $english): array {
            $plan = StudyPlan::query()->create([
                'organization_id' => $this->organizationId(),
                'user_id' => $this->student->getKey(),
                'exam_id' => $exam->getKey(),
                'syllabus_version_id' => $grammar->syllabus_version_id,
                'mode' => 'weekly',
                'language_id' => $english,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addDays(6)->toDateString(),
                'status' => 'active',
            ]);
            $study = StudyPlanDay::query()->create([
                'study_plan_id' => $plan->getKey(), 'day_number' => 1, 'plan_date' => now()->toDateString(), 'day_role' => 'study',
            ]);

            foreach ([$grammar, $polity] as $sequence => $topic) {
                StudyPlanItem::query()->create([
                    'study_plan_day_id' => $study->getKey(), 'item_type' => 'study', 'sequence' => $sequence,
                    'canonical_topic_id' => $topic->canonical, 'exam_topic_id' => $topic->exam_topic_id,
                ]);
            }

            $testDay = StudyPlanDay::query()->create([
                'study_plan_id' => $plan->getKey(), 'day_number' => 7, 'plan_date' => now()->addDays(6)->toDateString(), 'day_role' => 'test',
            ]);
            StudyPlanItem::query()->create([
                'study_plan_day_id' => $testDay->getKey(), 'item_type' => 'test', 'test_kind' => 'weekly',
                'question_count' => 10, 'scope_days' => 7, 'sequence' => 0,
            ]);

            return [$plan, $testDay];
        });

        $testId = $this->actingAs($this->student)
            ->postJson("/api/v1/study-plans/{$plan->getKey()}/days/{$testDay->getKey()}/test")
            ->assertCreated()
            ->assertJsonPath('data.short_paper', false)
            ->json('data.test_id');

        $questions = $this->actingAs($this->student)
            ->postJson("/api/v1/tests/{$testId}/attempt")
            ->assertCreated()
            ->json('data.questions');

        $partA = array_values(array_filter($questions, static fn (array $q): bool => array_keys($q['texts']) === ['ta']));
        $partB = array_values(array_filter($questions, static fn (array $q): bool => array_keys($q['texts']) === ['en']));

        $this->assertCount(10, $questions);
        $this->assertNotEmpty($partA, 'Part A is on an English plan test, in Tamil.');
        $this->assertNotEmpty($partB);
        $this->assertCount(10, [...$partA, ...$partB], 'Every question prints in exactly one language.');
        $this->assertStringContainsString('இலக்கண', $partA[0]['question_text']);
        $this->assertSame(1, $partA[0]['sequence'], 'Part A comes first, as on the real paper.');

        $fixed = TenantContext::withoutTenancy(fn () => DB::table('test_questions')->where('test_id', $testId)->whereNotNull('fixed_language_id')->pluck('fixed_language_id')->unique()->values()->all());
        $this->assertSame([$tamil], $fixed);
    }

    public function test_a_short_paper_is_built_but_flagged_with_its_gaps(): void
    {
        $exam = $this->seedTnpsc();
        $topic = $this->tnpscTopic('Indian Polity');
        $this->questionsOn($exam, $topic->canonical, 8);

        $data = $this->actingAs($this->student)->postJson('/api/v1/tests', [
            'exam_id' => $exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
            'type' => 'topic_practice',
            'question_count' => 20,
            'exam_topic_ids' => [$topic->exam_topic_id],
        ])->assertCreated()->json('data');

        // The student still gets the eight there are...
        $this->assertSame('ready', $data['status']);
        $this->assertSame(8, $data['question_count']);

        // ...and is told the paper is short, and by how much.
        $this->assertTrue($data['short_paper']);
        $this->assertSame(['asked' => 20, 'selected' => 8, 'missing' => 12], array_intersect_key($data['shortfall'], array_flip(['asked', 'selected', 'missing'])));
        $this->assertStringContainsString('20', $data['shortfall']['message']);

        $gaps = collect($data['selection_profile']['gaps']);
        $paper = $gaps->firstWhere('scope', 'paper');
        // Stored as jsonb, which keeps no key order.
        $this->assertEquals(['scope' => 'paper', 'needed' => 20, 'available' => 8, 'deficit' => 12], $paper);
        $this->assertNotNull($gaps->first(static fn (array $g): bool => $g['scope'] === 'topic' && $g['canonical_topic_id'] === $topic->canonical));

        // A paper that is not short says so too.
        $full = $this->actingAs($this->student)->postJson('/api/v1/tests', [
            'exam_id' => $exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
            'type' => 'topic_practice',
            'question_count' => 5,
            'exam_topic_ids' => [$topic->exam_topic_id],
        ])->assertCreated()->json('data');

        $this->assertFalse($full['short_paper']);
        $this->assertNull($full['shortfall']);
    }

    public function test_a_whole_syllabus_paper_follows_the_exam_weights(): void
    {
        $exam = $this->seedTnpsc();
        // Polity is worth 15 of 200, General Science 5: with stock in both,
        // a paper on the two gives Polity about three times as many.
        $polity = $this->tnpscTopic('Indian Polity');
        $science = $this->tnpscTopic('General Science');
        $this->questionsOn($exam, $polity->canonical, 30, quality: 50);
        $this->questionsOn($exam, $science->canonical, 30, quality: 99);

        $counts = $this->topicCounts($this->assemble($exam, [$polity->canonical, $science->canonical], 20, ['type' => 'model']));

        $this->assertGreaterThan($counts[$science->canonical], $counts[$polity->canonical]);
        $this->assertGreaterThanOrEqual(1, $counts[$science->canonical]);
    }
}
