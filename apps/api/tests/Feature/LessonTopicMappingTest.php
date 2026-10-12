<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Topics\LessonTopicMapper;
use App\Models\Book;
use App\Models\BookChapter;
use App\Models\BookLesson;
use App\Models\CanonicalTopic;
use App\Models\Language;
use App\Models\Question;
use App\Models\Source;
use App\Models\SourceChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * A lesson's topic is what lets the questions generated from it count: in the
 * backlog, in blueprint weighting, in prediction. These pin both the mapping
 * and the refusals around it.
 */
final class LessonTopicMappingTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private Source $source;

    private Book $book;

    private BookChapter $chapter;

    /** @var array<string, CanonicalTopic> */
    private array $topics = [];

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\AiModelSeeder::class);
        $this->seed(\Database\Seeders\PromptSeeder::class);

        $this->source = $this->makeSource('Science Std 9');

        TenantContext::withoutTenancy(function (): void {
            foreach ([
                'physics-force-motion' => 'Physics: Force, Motion and Energy',
                'botany-plant-kingdom' => 'Botany: Plant Kingdom',
                'indian-polity' => 'Indian Polity and Governance',
            ] as $slug => $name) {
                $this->topics[$slug] = CanonicalTopic::query()->create([
                    'slug' => $slug, 'name' => $name,
                    'domain' => 'general_studies', 'path' => str_replace('-', '_', $slug), 'depth' => 1,
                ]);
            }

            $this->book = Book::query()->create([
                'source_id' => $this->source->getKey(),
                'organization_id' => null,
                'medium_language_id' => Language::byCode('en')->getKey(),
            ]);

            $this->chapter = BookChapter::query()->create([
                'book_id' => $this->book->getKey(), 'title' => 'Science',
                'page_start' => 1, 'page_end' => 40, 'sequence' => 1,
            ]);
        });
    }

    private function lesson(string $title, string $body, int $sequence): BookLesson
    {
        return TenantContext::withoutTenancy(function () use ($title, $body, $sequence): BookLesson {
            $lesson = BookLesson::query()->create([
                'book_chapter_id' => $this->chapter->getKey(), 'title' => $title,
                'page_start' => $sequence, 'page_end' => $sequence, 'sequence' => $sequence,
            ]);

            SourceChunk::query()->create([
                'source_id' => $this->source->getKey(), 'book_id' => $this->book->getKey(),
                'book_lesson_id' => $lesson->getKey(), 'chunk_index' => $sequence,
                'breadcrumb' => "Science › {$title}", 'text' => $body, 'token_count' => 40,
                'language_id' => Language::byCode('en')->getKey(), 'content_hash' => md5($body),
            ]);

            return $lesson;
        });
    }

    /** @param  array<int, array<string, mixed>>  $mappings */
    private function modelAnswers(array $mappings): void
    {
        $test = $this;

        $this->app->instance(LlmClient::class, new class($mappings, $test) implements LlmClient
        {
            /** @param  array<int, array<string, mixed>>  $mappings */
            public function __construct(private readonly array $mappings, private readonly LessonTopicMappingTest $test) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->test->countCall();

                return new LlmResponse(['mappings' => $this->mappings], $request->modelKey, 10, 10);
            }

            public function modelKey(LlmRequest $request): string
            {
                return $request->modelKey;
            }
        });
    }

    public function countCall(): void
    {
        $this->calls++;
    }

    private function map(): array
    {
        return TenantContext::withoutTenancy(fn () => app(LessonTopicMapper::class)->mapBook($this->book));
    }

    private function topicOf(BookLesson $lesson): ?string
    {
        return TenantContext::withoutTenancy(fn () => app(LessonTopicMapper::class)->topicForLesson($lesson->getKey()));
    }

    public function test_titles_and_recurring_phrases_map_without_the_model(): void
    {
        $this->modelAnswers([]);
        $force = $this->lesson('Force and Motion', 'A push or a pull is a force. Motion is a change of position.', 1);
        $plants = $this->lesson('The Living World of Plants', 'The plant kingdom is divided into groups. Every member of the plant kingdom makes food.', 2);

        $stats = $this->map();

        $this->assertSame(2, $stats['lexical']);
        $this->assertSame(0, $this->calls, 'Lessons settled lexically must not cost a model call.');
        $this->assertSame($this->topics['physics-force-motion']->getKey(), $this->topicOf($force));
        $this->assertSame($this->topics['botany-plant-kingdom']->getKey(), $this->topicOf($plants));
    }

    public function test_the_model_maps_the_rest_but_cannot_invent_a_topic(): void
    {
        $constitution = $this->lesson('Our Constitution', 'The rights of citizens are written down.', 1);
        $mystery = $this->lesson('Miscellany', 'Assorted notes.', 2);

        $this->modelAnswers([
            ['lesson_id' => $constitution->getKey(), 'topic_slug' => 'indian-polity', 'confidence' => 0.88],
            // Invented slug: must be discarded, not added to the taxonomy.
            ['lesson_id' => $mystery->getKey(), 'topic_slug' => 'general-knowledge', 'confidence' => 0.99],
            // A lesson it was never asked about.
            ['lesson_id' => '00000000-0000-7000-8000-000000000000', 'topic_slug' => 'indian-polity', 'confidence' => 0.99],
        ]);

        $stats = $this->map();

        $this->assertSame(1, $stats['model']);
        $this->assertSame(1, $stats['unmapped']);
        $this->assertSame($this->topics['indian-polity']->getKey(), $this->topicOf($constitution));
        $this->assertNull($this->topicOf($mystery));
        $this->assertFalse(TenantContext::withoutTenancy(
            fn () => CanonicalTopic::query()->where('slug', 'general-knowledge')->exists()
        ));
    }

    public function test_existing_questions_are_filed_under_their_lessons_topic(): void
    {
        $this->modelAnswers([]);
        $force = $this->lesson('Force and Motion', 'Force changes motion.', 1);

        $untagged = $this->makeQuestion(1, 'APPROVED', $this->source);
        $alreadyTagged = $this->makeQuestion(2, 'APPROVED', $this->source, null, [
            'canonical_topic_id' => $this->topics['indian-polity']->getKey(),
        ]);

        TenantContext::withoutTenancy(fn () => DB::table('question_sources')
            ->whereIn('question_id', [$untagged->getKey(), $alreadyTagged->getKey()])
            ->update(['book_lesson_id' => $force->getKey()]));

        $stats = $this->map();

        $this->assertSame(1, $stats['questions_tagged']);
        TenantContext::withoutTenancy(function () use ($untagged, $alreadyTagged): void {
            $this->assertSame($this->topics['physics-force-motion']->getKey(), Question::query()->find($untagged->getKey())->canonical_topic_id);
            // A topic that was already set is not second-guessed by a re-run.
            $this->assertSame($this->topics['indian-polity']->getKey(), Question::query()->find($alreadyTagged->getKey())->canonical_topic_id);
        });
    }

    public function test_a_human_choice_survives_a_rerun_and_retags_its_questions(): void
    {
        $this->modelAnswers([]);
        $force = $this->lesson('Force and Motion', 'Force changes motion.', 1);
        $question = $this->makeQuestion(1, 'APPROVED', $this->source);
        TenantContext::withoutTenancy(fn () => DB::table('question_sources')
            ->where('question_id', $question->getKey())
            ->update(['book_lesson_id' => $force->getKey()]));

        $this->map();

        // A person decides the lesson is really about governance.
        TenantContext::withoutTenancy(fn () => app(LessonTopicMapper::class)
            ->assignByHand($force, $this->topics['indian-polity']->getKey(), null));

        $stats = $this->map();

        $this->assertSame(1, $stats['human']);
        $this->assertSame($this->topics['indian-polity']->getKey(), $this->topicOf($force));
        $this->assertSame(
            $this->topics['indian-polity']->getKey(),
            TenantContext::withoutTenancy(fn () => Question::query()->find($question->getKey())->canonical_topic_id),
        );
        $this->assertTrue(TenantContext::withoutTenancy(
            fn () => DB::table('audit_logs')->where('action', 'lesson.topic_assigned')->exists()
        ));
    }

    public function test_tamil_words_survive_normalisation_and_do_not_match_by_fragment(): void
    {
        // Vowel signs and the pulli are combining marks; stripping them used
        // to turn every Tamil word into one-letter fragments that "matched".
        $this->assertSame('தமிழ்த்தேன்', \App\Domain\Topics\TopicPhrases::normalise('தமிழ்த்தேன்!'));

        TenantContext::withoutTenancy(fn () => $this->topics['indian-polity']->forceFill(['aliases' => ['கவிதை']])->save());
        $this->modelAnswers([]);
        $lesson = $this->lesson('தமிழ்த்தேன்', 'தமிழ் மொழியின் இனிமை பற்றிய பாடல்.', 1);

        $stats = $this->map();

        $this->assertSame(0, $stats['lexical']);
        $this->assertNull($this->topicOf($lesson));
    }

    public function test_a_stale_automatic_mapping_is_withdrawn_and_its_questions_follow(): void
    {
        $this->modelAnswers([]);
        $lesson = $this->lesson('Miscellany', 'Assorted notes with no topic in them.', 1);
        $question = $this->makeQuestion(1, 'APPROVED', $this->source, null, [
            'canonical_topic_id' => $this->topics['indian-polity']->getKey(),
        ]);

        TenantContext::withoutTenancy(function () use ($lesson, $question): void {
            DB::table('question_sources')->where('question_id', $question->getKey())->update(['book_lesson_id' => $lesson->getKey()]);
            // A wrong match left behind by an earlier, looser run.
            DB::table('book_topic_mappings')->insert([
                'book_lesson_id' => $lesson->getKey(),
                'canonical_topic_id' => $this->topics['indian-polity']->getKey(),
                'confidence' => 0.9, 'mapped_by' => 'lexical', 'created_at' => now(),
            ]);
        });

        $this->map();

        $this->assertNull($this->topicOf($lesson));
        // The question inherited that guess, so it is un-filed with it.
        $this->assertNull(TenantContext::withoutTenancy(fn () => Question::query()->find($question->getKey())->canonical_topic_id));
    }

    public function test_the_console_lists_and_corrects_lesson_topics(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->modelAnswers([]);
        $admin = $this->makeAdmin();
        $force = $this->lesson('Force and Motion', 'Force changes motion.', 1);
        $this->map();

        $this->actingAs($admin)
            ->getJson("/api/v1/sources/{$this->source->getKey()}/topic-mappings")
            ->assertOk()
            ->assertJsonPath('meta.mapped', 1)
            ->assertJsonPath('data.0.topic_slug', 'physics-force-motion')
            ->assertJsonPath('data.0.mapped_by', 'lexical');

        $this->actingAs($admin)
            ->putJson("/api/v1/sources/{$this->source->getKey()}/lessons/{$force->getKey()}/topic", [
                'canonical_topic_id' => $this->topics['indian-polity']->getKey(),
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->getJson("/api/v1/sources/{$this->source->getKey()}/topic-mappings")
            ->assertJsonPath('data.0.topic_slug', 'indian-polity')
            ->assertJsonPath('data.0.mapped_by', 'human');

        // A platform book's lessons are platform content.
        $orgAdmin = $this->makeAdmin('org-admin@example.test', 'organization_admin', platformAdmin: false);
        $this->actingAs($orgAdmin)
            ->putJson("/api/v1/sources/{$this->source->getKey()}/lessons/{$force->getKey()}/topic", ['canonical_topic_id' => null])
            ->assertForbidden();
    }

    public function test_a_model_outage_leaves_lessons_unmapped_rather_than_guessed(): void
    {
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function complete(LlmRequest $request): LlmResponse
            {
                throw new \RuntimeException('provider down');
            }

            public function modelKey(LlmRequest $request): string
            {
                return $request->modelKey;
            }
        });

        $lesson = $this->lesson('Our Constitution', 'Rights of citizens.', 1);

        $stats = $this->map();

        $this->assertSame(1, $stats['unmapped']);
        $this->assertNull($this->topicOf($lesson));
    }
}
