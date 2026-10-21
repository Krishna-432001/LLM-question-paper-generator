<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Question;
use App\Models\Review;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/** Approving written questions a chapter at a time, on a spot-checked sample. */
final class ChapterReviewTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $this->source = $this->makeSource('Std 8 Social Science Term-Full (English)');
    }

    public function test_chapters_are_listed_with_their_waiting_counts(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 3);
        $this->importChapter('History Unit 3: Rural Life and Society', 2);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/chapter-reviews')
            ->assertOk()
            ->assertJsonPath('meta.chapters', 2)
            ->assertJsonPath('meta.pending', 5);

        // Most waiting first.
        $this->assertSame(
            ['Std 8 — History Unit 2: From Trade to Territory', 'Std 8 — History Unit 3: Rural Life and Society'],
            array_column($response->json('data'), 'breadcrumb'),
        );
        $this->assertSame(3, $response->json('data.0.pending'));
        $this->assertSame($this->source->title, $response->json('data.0.source'));
    }

    public function test_a_sample_shows_both_languages_the_page_and_the_quote(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 3);
        $admin = $this->makeAdmin();
        $key = $this->actingAs($admin)->getJson('/api/v1/chapter-reviews')->json('data.0.key');

        $sample = $this->actingAs($admin)
            ->getJson("/api/v1/chapter-reviews/{$key}/sample?size=2")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $sample['questions']);
        $first = $sample['questions'][0];
        $this->assertSame('B', $first['correct_option']);
        $this->assertSame(12, $first['page']);
        $this->assertStringContainsString('Plassey', $first['quote']);
        $this->assertStringContainsString('battle', $first['master']['question_text']);
        $this->assertStringContainsString('போர்', $first['tamil']['question_text']);
        $this->assertNotNull($first['review_id']);
    }

    public function test_approving_a_chapter_approves_its_waiting_questions_and_leaves_other_chapters_alone(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 3);
        $this->importChapter('History Unit 3: Rural Life and Society', 2);
        $admin = $this->makeAdmin();
        $chapters = $this->actingAs($admin)->getJson('/api/v1/chapter-reviews')->json('data');
        $key = $chapters[0]['key'];
        $checked = array_column(
            $this->actingAs($admin)->getJson("/api/v1/chapter-reviews/{$key}/sample?size=2")->json('data.questions'),
            'question_id',
        );

        $this->actingAs($admin)
            ->postJson("/api/v1/chapter-reviews/{$key}/approve", ['checked_question_ids' => $checked])
            ->assertOk()
            ->assertJsonPath('data.approved', 3)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.remaining', 0);

        TenantContext::withoutTenancy(function () use ($admin): void {
            $byChapter = DB::table('question_sources as qs')
                ->join('questions as q', 'q.id', '=', 'qs.question_id')
                ->selectRaw('qs.breadcrumb, q.status, count(*) as n')
                ->groupBy('qs.breadcrumb', 'q.status')
                ->get()
                ->mapWithKeys(fn ($r) => [str_contains($r->breadcrumb, 'Unit 2') ? 'unit2:'.$r->status : 'unit3:'.$r->status => (int) $r->n])
                ->all();

            $this->assertSame(['unit2:APPROVED' => 3, 'unit3:PENDING_REVIEW' => 2], collect($byChapter)->sortKeys()->all());

            // Each one attributed and explained, as if approved at the workstation.
            $review = Review::query()->where('status', 'approved')->firstOrFail();
            $this->assertSame($admin->getKey(), $review->reviewed_by);
            $this->assertStringContainsString('spot-check of 2 questions', (string) $review->decision_reason);
            $this->assertSame($admin->getKey(), Question::query()->findOrFail($review->reviewable_id)->approved_by);
            $this->assertSame(3, DB::table('audit_logs')->where('action', 'question.approved')->count());
        });

        // The approved chapter drops off the list.
        $this->actingAs($admin)->getJson('/api/v1/chapter-reviews')->assertJsonPath('meta.chapters', 1);
    }

    public function test_a_chapter_cannot_be_approved_without_reading_any_of_it(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 2);
        $admin = $this->makeAdmin();
        $key = $this->actingAs($admin)->getJson('/api/v1/chapter-reviews')->json('data.0.key');

        // An id that is not one of this chapter's waiting questions proves nothing was read.
        $this->actingAs($admin)
            ->postJson("/api/v1/chapter-reviews/{$key}/approve", ['checked_question_ids' => ['0193c8a0-0000-7000-8000-000000000000']])
            ->assertStatus(409);

        $this->actingAs($admin)
            ->postJson("/api/v1/chapter-reviews/{$key}/approve", ['checked_question_ids' => []])
            ->assertUnprocessable();

        $this->assertSame(2, TenantContext::withoutTenancy(fn () => Question::query()->where('status', 'PENDING_REVIEW')->count()));
    }

    public function test_a_question_another_reviewer_is_holding_is_left_alone(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 2);
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin('other-reviewer@example.test');

        TenantContext::withoutTenancy(function () use ($other): void {
            Review::query()->orderBy('created_at')->firstOrFail()->forceFill([
                'status' => 'in_review',
                'assigned_to' => $other->getKey(),
                'assigned_at' => now(),
                'claim_expires_at' => now()->addMinutes(Review::CLAIM_MINUTES),
            ])->save();
        });

        $key = $this->actingAs($admin)->getJson('/api/v1/chapter-reviews')->json('data.0.key');
        $checked = array_column($this->actingAs($admin)->getJson("/api/v1/chapter-reviews/{$key}/sample")->json('data.questions'), 'question_id');

        $this->actingAs($admin)
            ->postJson("/api/v1/chapter-reviews/{$key}/approve", ['checked_question_ids' => $checked])
            ->assertOk()
            ->assertJsonPath('data.approved', 1)
            ->assertJsonPath('data.skipped', 1)
            // The held question is not "remaining": asking again could not approve it.
            ->assertJsonPath('data.remaining', 0);
    }

    public function test_a_large_chapter_is_approved_in_batches(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 5);
        $admin = $this->makeAdmin();
        $key = $this->actingAs($admin)->getJson('/api/v1/chapter-reviews')->json('data.0.key');
        $checked = array_column($this->actingAs($admin)->getJson("/api/v1/chapter-reviews/{$key}/sample?size=1")->json('data.questions'), 'question_id');

        $this->actingAs($admin)
            ->postJson("/api/v1/chapter-reviews/{$key}/approve", ['checked_question_ids' => $checked, 'batch' => 2])
            ->assertOk()
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.remaining', 3);

        // The same spot-check carries the later batches, even once the
        // questions it read have themselves been approved.
        foreach ([[2, 1], [1, 0]] as [$approved, $remaining]) {
            $this->actingAs($admin)
                ->postJson("/api/v1/chapter-reviews/{$key}/approve", ['checked_question_ids' => $checked, 'batch' => 2])
                ->assertOk()
                ->assertJsonPath('data.approved', $approved)
                ->assertJsonPath('data.remaining', $remaining);
        }

        $this->assertSame(5, TenantContext::withoutTenancy(fn () => Question::query()->where('status', 'APPROVED')->count()));
    }

    public function test_a_book_chapter_is_not_approved_past_fifty_questions(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 30);
        $this->importChapter('History Unit 2: From Trade to Territory', 25, offset: 30);
        $admin = $this->makeAdmin();
        $key = $this->actingAs($admin)->getJson('/api/v1/chapter-reviews')->json('data.0.key');
        $checked = array_column($this->actingAs($admin)->getJson("/api/v1/chapter-reviews/{$key}/sample")->json('data.questions'), 'question_id');

        $this->actingAs($admin)
            ->postJson("/api/v1/chapter-reviews/{$key}/approve", ['checked_question_ids' => $checked])
            ->assertStatus(409)
            ->assertJsonPath('detail', fn ($detail) => str_contains((string) $detail, 'at most 50'));

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Question::query()->where('status', 'APPROVED')->count()));
    }

    public function test_only_platform_reviewers_may_use_it(): void
    {
        $this->importChapter('History Unit 2: From Trade to Territory', 1);
        $student = $this->makeAdmin('student@example.test', 'student', platformAdmin: false);

        $this->actingAs($student)->getJson('/api/v1/chapter-reviews')->assertForbidden();
    }

    public function test_an_unknown_chapter_is_not_found(): void
    {
        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/chapter-reviews/'.str_repeat('a', 32).'/sample')
            ->assertNotFound();
    }

    /** Imports $count questions under one chapter breadcrumb through questions:import. */
    private function importChapter(string $chapter, int $count, int $offset = 0): void
    {
        $questions = [];

        for ($i = $offset + 1; $i <= $offset + $count; $i++) {
            $questions[] = [
                'topic' => 'indian-national-movement',
                'format' => 'mcq4',
                'difficulty' => 'easy',
                'bloom' => 'remember',
                'page' => 12,
                'quote' => 'The battle of Plassey took place between the British East India Company and the Nawab of Bengal and his French allies.',
                'answer' => 'B',
                'en' => [
                    'q' => "{$chapter}: which battle, number {$i}, marked the start of British rule in Bengal?",
                    'options' => ['Buxar', 'Plassey', 'Wandiwash', 'Assaye'],
                    'explanation' => 'Plassey (1757) began British political sway over India.',
                ],
                'ta' => [
                    'q' => "{$chapter}: வங்காளத்தில் ஆங்கிலேய ஆட்சியைத் தொடங்கிய போர் எது? ({$i})",
                    'options' => ['பக்சார்', 'பிளாசி', 'வந்தவாசி', 'அசாய்'],
                    'explanation' => 'பிளாசிப் போர் (1757) ஆங்கிலேய ஆதிக்கத்தின் தொடக்கம்.',
                ],
            ];
        }

        $path = tempnam(sys_get_temp_dir(), 'ef-chapter-');
        file_put_contents($path, json_encode([
            'exam' => 'TNPSC_G4',
            'source' => $this->source->title,
            'breadcrumb' => "Std 8 — {$chapter}",
            'questions' => $questions,
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('questions:import', ['file' => $path])->assertSuccessful();
    }
}
