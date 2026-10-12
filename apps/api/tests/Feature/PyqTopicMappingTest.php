<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Pyq\TopicMapper;
use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\Exam;
use App\Models\PreviousYearPaper;
use App\Models\PreviousYearQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Mapping decides which topic a past question counts towards, and every
 * frequency score — and therefore every generated paper's topic balance —
 * inherits that decision. A wrong topic is worse than no topic, because it is
 * silent. These tests pin the refusals rather than the happy path.
 */
final class PyqTopicMappingTest extends TestCase
{
    use RefreshDatabase;

    private PreviousYearPaper $paper;

    private CanonicalTopic $polity;

    protected function setUp(): void
    {
        parent::setUp();

        // The gateway is final by design, so the stub goes in at the provider
        // boundary instead. That also means these tests exercise the real
        // prompt resolution, schema validation and cost accounting rather than
        // stepping over them.
        $this->seed(\Database\Seeders\AiModelSeeder::class);
        $this->seed(\Database\Seeders\PromptSeeder::class);

        TenantContext::withoutTenancy(function (): void {
            $exam = Exam::query()->create([
                'organization_id' => null, 'code' => 'MAP_EXAM',
                'name' => 'Map Exam', 'is_active' => true,
            ]);

            $this->polity = CanonicalTopic::query()->create([
                'slug' => 'indian-polity', 'name' => 'Indian Polity and Governance',
                'domain' => 'general_studies', 'path' => 'indian-polity', 'depth' => 0,
            ]);

            CanonicalTopic::query()->create([
                'slug' => 'physical-geography', 'name' => 'Physical Geography',
                'domain' => 'general_studies', 'path' => 'physical-geography', 'depth' => 0,
            ]);

            $this->paper = PreviousYearPaper::query()->create([
                'organization_id' => null, 'exam_id' => $exam->getKey(),
                'year' => 2023, 'paper_name' => 'Paper 2023', 'status' => 'parsed',
            ]);
        });
    }

    private function addQuestion(string $stem, int $number = 1): PreviousYearQuestion
    {
        return TenantContext::withoutTenancy(fn () => PreviousYearQuestion::query()->create([
            'paper_id' => $this->paper->getKey(),
            'question_number' => $number,
            'question_text' => $stem,
            'question_hash' => PreviousYearQuestion::hashFor($stem),
        ]));
    }

    /**
     * Binds a provider that answers with exactly these mappings.
     *
     * @param  array<int, array<string, mixed>>  $mappings
     */
    private function fakeClassifier(array $mappings, bool $throw = false): void
    {
        $this->app->instance(LlmClient::class, new class($mappings, $throw) implements LlmClient
        {
            public int $calls = 0;

            /** @param  array<int, array<string, mixed>>  $mappings */
            public function __construct(
                private readonly array $mappings,
                private readonly bool $throw,
            ) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls++;

                if ($this->throw) {
                    throw new RuntimeException('provider down');
                }

                return new LlmResponse(
                    data: ['mappings' => $this->mappings],
                    modelKey: $request->modelKey,
                    inputTokens: 10,
                    outputTokens: 10,
                );
            }

            public function modelKey(LlmRequest $request): string
            {
                return $request->modelKey;
            }
        });
    }

    /** Asserts the model was never consulted. */
    private function expectNoModelCall(): object
    {
        $client = new class implements LlmClient
        {
            public int $calls = 0;

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls++;

                return new LlmResponse(['mappings' => []], $request->modelKey, 0, 0);
            }

            public function modelKey(LlmRequest $request): string
            {
                return $request->modelKey;
            }
        };

        $this->app->instance(LlmClient::class, $client);

        return $client;
    }

    public function test_a_topic_named_in_the_stem_is_matched_without_a_model_call(): void
    {
        $this->addQuestion('Which body exercises Indian Polity and Governance functions?');

        // If a model call happens at all, the lexical layer failed to do its
        // job and every paper costs money it did not need to.
        $client = $this->expectNoModelCall();

        $result = app(TopicMapper::class)->mapPaper($this->paper->getKey());

        $this->assertSame(0, $client->calls, 'The lexical layer must settle this without a model call.');
        $this->assertSame(1, $result['lexical']);
        $this->assertSame(0, $result['model']);

        $question = PreviousYearQuestion::query()->firstOrFail();
        $this->assertSame($this->polity->getKey(), $question->canonical_topic_id);
        $this->assertSame('lexical', $question->mapping_source);
    }

    public function test_an_invented_topic_is_discarded_rather_than_created(): void
    {
        $question = $this->addQuestion('Who appoints the Governor of a State?');

        // The model returns a slug that is not in the taxonomy. Accepting it
        // would grow a category nobody defined and quietly corrupt every
        // frequency count that follows.
        $this->fakeClassifier([[
            'question_id' => $question->getKey(),
            'topic_slug' => 'constitutional-appointments',
            'confidence' => 0.97,
        ]]);

        $result = app(TopicMapper::class)->mapPaper($this->paper->getKey());

        $this->assertSame(0, $result['mapped']);
        $this->assertSame(1, $result['unmapped']);
        $this->assertNull($question->fresh()->canonical_topic_id);
        $this->assertSame(2, CanonicalTopic::query()->count());
    }

    public function test_a_low_confidence_mapping_is_recorded_but_flagged(): void
    {
        $question = $this->addQuestion('Who appoints the Governor of a State?');

        $this->fakeClassifier([[
            'question_id' => $question->getKey(),
            'topic_slug' => 'indian-polity',
            'confidence' => 0.55,
        ]]);

        $result = app(TopicMapper::class)->mapPaper($this->paper->getKey());

        $this->assertSame(1, $result['mapped']);
        $this->assertSame(1, $result['review']);

        // Mapped, so it counts — but marked, so a reviewer can find every
        // guess the model was unsure about without re-reading the paper.
        $this->assertSame('ai_review', $question->fresh()->mapping_source);
        $this->assertEqualsWithDelta(0.55, $question->fresh()->mapping_confidence, 0.001);
    }

    public function test_a_guess_below_the_floor_is_thrown_away(): void
    {
        $question = $this->addQuestion('Who appoints the Governor of a State?');

        $this->fakeClassifier([[
            'question_id' => $question->getKey(),
            'topic_slug' => 'indian-polity',
            'confidence' => 0.20,
        ]]);

        $result = app(TopicMapper::class)->mapPaper($this->paper->getKey());

        $this->assertSame(0, $result['mapped']);
        $this->assertNull($question->fresh()->canonical_topic_id);
    }

    public function test_a_classifier_outage_leaves_the_paper_retryable(): void
    {
        $question = $this->addQuestion('Who appoints the Governor of a State?');

        $this->fakeClassifier([], throw: true);

        // The important part is that it does not throw: a partial mapping run
        // must not abort, and the unmapped questions stay unmapped so the same
        // call can simply be repeated later.
        $result = app(TopicMapper::class)->mapPaper($this->paper->getKey());

        $this->assertSame(0, $result['mapped']);
        $this->assertSame(1, $result['unmapped']);
        $this->assertNull($question->fresh()->canonical_topic_id);
    }

    public function test_already_mapped_questions_are_not_reclassified(): void
    {
        $question = $this->addQuestion('A question already filed by a human.');

        TenantContext::withoutTenancy(fn () => $question->forceFill([
            'canonical_topic_id' => $this->polity->getKey(),
            'mapping_confidence' => 1.0,
            'mapping_source' => 'human',
        ])->save());

        $client = $this->expectNoModelCall();

        app(TopicMapper::class)->mapPaper($this->paper->getKey());

        $this->assertSame(0, $client->calls);

        // A reviewer's decision must survive a re-run, and re-mapping settled
        // questions would also pay for the same classification twice.
        $this->assertSame('human', $question->fresh()->mapping_source);
    }

}
