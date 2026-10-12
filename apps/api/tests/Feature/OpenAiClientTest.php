<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\DTOs\LlmRequest;
use App\Infrastructure\Ai\Providers\OpenAiClient;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;
use RuntimeException;
use Tests\TestCase;

/**
 * The OpenAI adapter, exercised against a faked transport.
 *
 * What is pinned here is the handful of places OpenAI differs from Anthropic
 * in ways that fail loudly at request time or, worse, silently at costing
 * time: strict-mode schemas, refusals delivered as HTTP 200, cached-token
 * accounting, and reasoning models rejecting sampling parameters.
 */
final class OpenAiClientTest extends TestCase
{
    private function request(array $schema = [], array $params = []): LlmRequest
    {
        return new LlmRequest(
            operation: 'question_generation_mcq',
            modelKey: 'claude-opus-5',
            systemPrompt: 'You write questions.',
            userPrompt: 'Generate 2 questions.',
            outputSchema: $schema ?: [
                'type' => 'object',
                'required' => ['questions'],
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            // Deliberately optional: strict mode forbids this.
                            'required' => ['question_text'],
                            'properties' => [
                                'question_text' => ['type' => 'string'],
                                'subtopic' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                ],
            ],
            params: $params,
        );
    }

    private function client(): OpenAiClient
    {
        return new OpenAiClient(
            http: app(Http::class),
            apiKey: 'sk-test',
            defaultModel: 'gpt-4o',
            modelMap: ['claude-opus-5' => 'gpt-4.1'],
        );
    }

    private function reply(array $content, array $usage = []): array
    {
        return [
            'model' => 'gpt-4.1',
            'choices' => [[
                'message' => ['content' => json_encode($content)],
                'finish_reason' => 'stop',
            ]],
            'usage' => $usage + ['prompt_tokens' => 100, 'completion_tokens' => 40],
        ];
    }

    public function test_it_rewrites_optional_properties_for_strict_mode(): void
    {
        HttpFacade::fake([
            'api.openai.com/*' => HttpFacade::response($this->reply(['questions' => []])),
        ]);

        $this->client()->complete($this->request());

        HttpFacade::assertSent(function ($request): bool {
            $item = $request['response_format']['json_schema']['schema']
                ['properties']['questions']['items'];

            // Strict mode rejects a property that is absent from `required`,
            // and our schemas mark optional fields exactly that way. Every
            // property must be listed, and the object sealed.
            $this->assertSame(['question_text', 'subtopic'], $item['required']);
            $this->assertFalse($item['additionalProperties']);
            $this->assertTrue($request['response_format']['json_schema']['strict']);

            // Nullability is what preserves the original meaning of optional.
            $this->assertSame(['string', 'null'], $item['properties']['subtopic']['type']);

            return true;
        });
    }

    public function test_it_maps_the_registry_model_onto_an_openai_id(): void
    {
        HttpFacade::fake([
            'api.openai.com/*' => HttpFacade::response($this->reply(['questions' => []])),
        ]);

        // Prompt versions name Anthropic models; sending that name to OpenAI
        // is an instant 400.
        $this->client()->complete($this->request());

        HttpFacade::assertSent(fn ($request): bool => $request['model'] === 'gpt-4.1');
    }

    public function test_a_refusal_is_not_reported_as_a_schema_failure(): void
    {
        HttpFacade::fake([
            'api.openai.com/*' => HttpFacade::response([
                'model' => 'gpt-4.1',
                'choices' => [[
                    // HTTP 200, no content, a refusal string instead.
                    'message' => ['refusal' => 'I cannot help with that.', 'content' => null],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 0],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined the request');

        $this->client()->complete($this->request());
    }

    public function test_it_records_cached_tokens_separately(): void
    {
        HttpFacade::fake([
            'api.openai.com/*' => HttpFacade::response($this->reply(
                ['questions' => []],
                ['prompt_tokens' => 500, 'prompt_tokens_details' => ['cached_tokens' => 400]],
            )),
        ]);

        $response = $this->client()->complete($this->request());

        // Cached input is billed at a fraction of the rate. Folding it into
        // the plain input count overstates the cost of every cached call.
        $this->assertSame(500, $response->inputTokens);
        $this->assertSame(400, $response->cachedInputTokens);
    }

    public function test_it_withholds_temperature_from_reasoning_models(): void
    {
        HttpFacade::fake([
            'api.openai.com/*' => HttpFacade::response($this->reply(['questions' => []])),
        ]);

        $client = new OpenAiClient(
            http: app(Http::class),
            apiKey: 'sk-test',
            defaultModel: 'o4-mini',
            modelMap: [],
        );

        $client->complete($this->request(params: ['temperature' => 0.7, 'effort' => 'high']));

        HttpFacade::assertSent(function ($request): bool {
            // Reasoning models 400 on sampling parameters but accept effort.
            $this->assertArrayNotHasKey('temperature', $request->data());
            $this->assertSame('high', $request['reasoning_effort']);

            return true;
        });
    }

    public function test_an_api_error_names_the_operation_that_failed(): void
    {
        HttpFacade::fake([
            'api.openai.com/*' => HttpFacade::response(
                ['error' => ['message' => 'Invalid schema.']], 400
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('question_generation_mcq');

        $this->client()->complete($this->request());
    }

    public function test_a_missing_key_fails_before_any_network_call(): void
    {
        HttpFacade::fake();

        $client = new OpenAiClient(
            http: app(Http::class),
            apiKey: '',
            defaultModel: 'gpt-4o',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');

        $client->complete($this->request());

        HttpFacade::assertNothingSent();
    }
}
