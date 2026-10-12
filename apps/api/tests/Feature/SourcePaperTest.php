<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Review\ReviewWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionExamMapping;
use App\Models\QuestionOption;
use App\Models\QuestionSource;
use App\Models\QuestionTranslation;
use App\Models\Review;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin console's whole reason to exist: a book goes in, a question paper
 * comes out. Everything between those two points is already covered elsewhere;
 * what is tested here is that the one-call path holds together and refuses
 * clearly rather than shipping an empty paper.
 */
final class SourcePaperTest extends TestCase
{
    use RefreshDatabase;

    private Exam $exam;

    private Language $english;

    private Organization $org;

    private User $admin;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'PAPER_EXAM',
                'name' => 'Paper Exam', 'is_active' => true,
            ]);

            $this->admin = User::query()->create([
                'name' => 'Admin', 'email' => 'paper-admin@example.test',
                'password' => 'secret-pass', 'is_platform_admin' => true,
            ]);

            $this->org = Organization::query()->create([
                'name' => 'Admin Org', 'slug' => 'paper-admin-org', 'type' => 'individual',
                'owner_user_id' => $this->admin->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $this->org->getKey(),
                'user_id' => $this->admin->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);

            $this->source = Source::query()->create([
                'organization_id' => $this->org->getKey(),
                'kind' => 'textbook',
                'title' => 'Test Science Reader',
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'govt_open',
                'status' => 'ready',
                'storage_disk' => 'local',
                'storage_path' => 'sources/test.pdf',
                'original_filename' => 'test.pdf',
                'file_size_bytes' => 1024,
                'content_sha256' => str_repeat('a', 64),
                'mime_type' => 'application/pdf',
            ]);
        });
    }

    public function test_it_builds_a_paper_from_one_books_approved_questions(): void
    {
        TenantContext::withoutTenancy(fn () => $this->makeQuestions(12, 'APPROVED'));

        $response = $this->actingAs($this->admin)->postJson(
            "/api/v1/sources/{$this->source->getKey()}/paper",
            ['question_count' => 8, 'title' => 'Unit Test One', 'duration_minutes' => 20],
        );

        $response->assertCreated();

        $data = $response->json('data');

        $this->assertSame(8, $data['question_count']);
        $this->assertSame(8, $data['requested_count']);

        $types = array_column($data['documents'], 'type');
        $this->assertContains('question_paper', $types);
        $this->assertContains('answer_key', $types);

        // Every document must be downloadable the moment the call returns —
        // a link to a document that is still rendering is not a deliverable.
        foreach ($data['documents'] as $document) {
            $this->assertNotEmpty($document['download_url']);
            $this->actingAs($this->admin)
                ->get($document['download_url'])
                ->assertOk();
        }
    }

    public function test_it_only_draws_from_the_book_it_was_asked_about(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->makeQuestions(6, 'APPROVED');

            // A second book with its own approved questions must not leak into
            // this paper — that is the entire point of scoping to a source.
            $other = Source::query()->create([
                'organization_id' => $this->org->getKey(),
                'kind' => 'textbook', 'title' => 'Other Reader',
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'govt_open', 'status' => 'ready',
                'storage_disk' => 'local', 'storage_path' => 'sources/other.pdf',
                'original_filename' => 'other.pdf',
                'file_size_bytes' => 1024, 'content_sha256' => str_repeat('b', 64),
                'mime_type' => 'application/pdf',
            ]);

            $this->makeQuestions(6, 'APPROVED', $other, 'other');
        });

        $data = $this->actingAs($this->admin)
            ->postJson("/api/v1/sources/{$this->source->getKey()}/paper", ['question_count' => 20])
            ->assertCreated()
            ->json('data');

        // Only the six from this book exist to be drawn.
        $this->assertSame(6, $data['question_count']);
        $this->assertSame(20, $data['requested_count']);
    }

    public function test_it_refuses_rather_than_printing_an_empty_paper(): void
    {
        TenantContext::withoutTenancy(fn () => $this->makeQuestions(4, 'PENDING_REVIEW'));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/sources/{$this->source->getKey()}/paper")
            ->assertStatus(409)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'none are approved'));
    }

    public function test_it_approves_a_whole_batch_and_closes_the_review_queue(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->makeQuestions(9, 'PENDING_REVIEW');

            // Generated questions arrive with a queue item attached; approving
            // the question without closing it would leave the reviewer looking
            // at work that is already done.
            $workflow = app(ReviewWorkflow::class);

            foreach (Question::query()->where('status', 'PENDING_REVIEW')->get() as $question) {
                $workflow->enqueue($question);
            }
        });

        $data = $this->actingAs($this->admin)
            ->postJson(
                "/api/v1/sources/{$this->source->getKey()}/questions/decide",
                ['decision' => 'approve'],
            )
            ->assertOk()
            ->json('data');

        $this->assertSame(9, $data['considered']);
        $this->assertSame(9, $data['decided']);
        $this->assertSame([], $data['failed']);
        $this->assertSame(9, $data['questions']['approved']);
        $this->assertSame(0, $data['questions']['pending_review']);

        TenantContext::withoutTenancy(function (): void {
            $this->assertSame(
                0,
                Review::query()->whereIn('status', ['pending', 'in_review'])->count(),
                'Approved questions must not be left sitting in the review queue.',
            );
        });
    }

    public function test_it_can_approve_only_the_strongest_candidates(): void
    {
        // makeQuestions scores 70 + (i % 20), so twelve questions run 70..81
        // and exactly four of them reach 78.
        TenantContext::withoutTenancy(fn () => $this->makeQuestions(12, 'PENDING_REVIEW'));

        $data = $this->actingAs($this->admin)
            ->postJson(
                "/api/v1/sources/{$this->source->getKey()}/questions/decide",
                ['decision' => 'approve', 'min_quality' => 78],
            )
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $data['considered']);
        $this->assertSame(4, $data['questions']['approved']);
        $this->assertSame(8, $data['questions']['pending_review']);
    }

    public function test_it_leaves_another_books_questions_alone(): void
    {
        TenantContext::withoutTenancy(function (): void {
            $this->makeQuestions(5, 'PENDING_REVIEW');

            $other = Source::query()->create([
                'organization_id' => $this->org->getKey(),
                'kind' => 'textbook', 'title' => 'Untouched Reader',
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'govt_open', 'status' => 'ready',
                'storage_disk' => 'local', 'storage_path' => 'sources/untouched.pdf',
                'original_filename' => 'untouched.pdf',
                'file_size_bytes' => 1024, 'content_sha256' => str_repeat('c', 64),
                'mime_type' => 'application/pdf',
            ]);

            $this->makeQuestions(5, 'PENDING_REVIEW', $other, 'untouched');
        });

        $this->actingAs($this->admin)
            ->postJson(
                "/api/v1/sources/{$this->source->getKey()}/questions/decide",
                ['decision' => 'approve'],
            )
            ->assertOk()
            ->assertJsonPath('data.decided', 5);

        TenantContext::withoutTenancy(function (): void {
            $this->assertSame(
                5,
                Question::query()->where('status', 'PENDING_REVIEW')->count(),
                'A bulk decision must not reach past the book it was aimed at.',
            );
        });
    }

    private function makeQuestions(
        int $count,
        string $status,
        ?Source $source = null,
        string $flavour = 'main',
    ): void {
        $source ??= $this->source;

        for ($i = 0; $i < $count; $i++) {
            $question = Question::query()->create([
                'organization_id' => $this->org->getKey(),
                'master_language_id' => $this->english->getKey(),
                'difficulty' => ['easy', 'medium', 'hard'][$i % 3],
                'status' => $status,
                'origin' => 'ai_generated',
                'quality_score' => 70 + ($i % 20),
            ]);

            foreach (['A' => false, 'B' => true, 'C' => false, 'D' => false] as $key => $correct) {
                QuestionOption::query()->create([
                    'question_id' => $question->getKey(),
                    'option_key' => $key,
                    'position' => ord($key) - 64,
                    'is_correct' => $correct,
                ]);
            }

            QuestionTranslation::query()->create([
                'question_id' => $question->getKey(),
                'language_id' => $this->english->getKey(),
                'is_master' => true,
                // Distinct wording: the selection engine drops near-duplicates,
                // which is correct but would make this fixture test nothing.
                // The index is fused into a word ("marker7"), not left as a
                // bare digit: the diversity filter drops tokens of three
                // characters or fewer, so a lone number makes every stem here
                // look like the same question.
                'question_text' => sprintf(
                    'Which %s process governs %s inside the %s specimen marker%d?',
                    ['thermal', 'chemical', 'optical', 'kinetic', 'magnetic'][$i % 5],
                    ['diffusion', 'refraction', 'oxidation', 'conduction'][$i % 4],
                    $flavour,
                    $i,
                ),
                'option_a' => "Alpha {$flavour} {$i}",
                'option_b' => "Beta {$flavour} {$i}",
                'option_c' => "Gamma {$flavour} {$i}",
                'option_d' => "Delta {$flavour} {$i}",
                'translation_status' => 'HUMAN_APPROVED',
            ]);

            QuestionExamMapping::query()->create([
                'question_id' => $question->getKey(),
                'exam_id' => $this->exam->getKey(),
                'is_active' => true,
            ]);

            QuestionSource::query()->create([
                'question_id' => $question->getKey(),
                'source_id' => $source->getKey(),
                'is_primary' => true,
                'supporting_quote' => "Quoted line {$flavour} {$i} from the book.",
            ]);
        }
    }
}
