<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/** A practice test on chosen syllabus units or topics, and nothing else. */
final class TopicWiseTestTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

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

        $this->student = $this->makeAdmin('topic-student@example.test', 'student', platformAdmin: false);
        $this->exam = TenantContext::withoutTenancy(fn (): Exam => Exam::query()->where('code', 'TNPSC_G4')->firstOrFail());
        $this->source = $this->makeSource('Std 6-10 books');
    }

    /** @return object{unit_id: string, topic_id: string, canonical: string} a topic of the unit filed under no other unit */
    private function topicIn(string $unitFragment, int $nth = 0): object
    {
        return TenantContext::withoutTenancy(function () use ($unitFragment, $nth): object {
            $unitId = DB::table('exam_units')->where('name', 'like', "%{$unitFragment}%")->value('id');
            $elsewhere = DB::table('exam_topics')->where('exam_unit_id', '!=', $unitId)->whereNotNull('canonical_topic_id')->pluck('canonical_topic_id');

            return DB::table('exam_topics')
                ->where('exam_unit_id', $unitId)
                ->whereNotNull('canonical_topic_id')
                ->whereNotIn('canonical_topic_id', $elsewhere)
                ->orderBy('sequence')
                ->offset($nth)
                ->select('exam_unit_id as unit_id', 'id as topic_id', 'canonical_topic_id as canonical')
                ->first();
        });
    }

    private function questionsOn(string $canonical, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeQuestion($this->sequence++, 'APPROVED', $this->source, $this->exam, ['canonical_topic_id' => $canonical]);
        }
    }

    /** @return list<string> the canonical topics of the test's questions */
    private function topicsOf(string $testId): array
    {
        return TenantContext::withoutTenancy(fn () => DB::table('test_questions as tq')
            ->join('questions as q', 'q.id', '=', 'tq.question_id')
            ->where('tq.test_id', $testId)
            ->distinct()
            ->pluck('q.canonical_topic_id')
            ->map(static fn ($id): string => (string) $id)
            ->sort()->values()->all());
    }

    /** @param  array<string, mixed>  $scope */
    private function create(array $scope, int $count = 5): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)->postJson('/api/v1/tests', $scope + [
            'exam_id' => $this->exam->getKey(),
            'language_id' => Language::byCode('en')->getKey(),
            'type' => 'topic_practice',
            'question_count' => $count,
        ]);
    }

    public function test_a_unit_test_draws_only_on_that_units_topics(): void
    {
        $polityA = $this->topicIn('Indian Polity', 0);
        $polityB = $this->topicIn('Indian Polity', 1);
        $geography = $this->topicIn('Geography');
        $this->questionsOn($polityA->canonical, 4);
        $this->questionsOn($polityB->canonical, 4);
        $this->questionsOn($geography->canonical, 20);

        $testId = $this->create(['exam_unit_ids' => [$polityA->unit_id]], 8)->assertCreated()->json('data.id');

        $expected = [$polityA->canonical, $polityB->canonical];
        sort($expected);
        $this->assertSame($expected, $this->topicsOf($testId));
    }

    public function test_a_topic_test_draws_only_on_that_topic(): void
    {
        $polityA = $this->topicIn('Indian Polity', 0);
        $polityB = $this->topicIn('Indian Polity', 1);
        $this->questionsOn($polityA->canonical, 6);
        $this->questionsOn($polityB->canonical, 6);

        $testId = $this->create(['exam_topic_ids' => [$polityB->topic_id]])->assertCreated()->json('data.id');

        $this->assertSame([$polityB->canonical], $this->topicsOf($testId));
    }

    public function test_topics_with_no_questions_are_refused_not_widened_to_the_whole_syllabus(): void
    {
        $this->questionsOn($this->topicIn('Geography')->canonical, 10);
        $empty = $this->topicIn('Indian Polity');

        $this->create(['exam_topic_ids' => [$empty->topic_id]])->assertStatus(422);

        // A topic the bank cannot see at all (no such row) is no different.
        $this->create(['exam_topic_ids' => ['00000000-0000-7000-8000-000000000000']])->assertStatus(422);
    }
}
