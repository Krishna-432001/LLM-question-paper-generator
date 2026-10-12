<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\Exceptions\SchemaValidationException;
use App\Infrastructure\Ai\Providers\OllamaClient;
use App\Infrastructure\Embeddings\OllamaEmbeddingProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The local-model driver, against a faked Ollama server: these never need
 * Ollama installed, and never call one.
 */
final class OllamaClientTest extends TestCase
{
    private function client(): OllamaClient
    {
        return new OllamaClient(
            http: app(Factory::class),
            baseUrl: 'http://ollama.test:11434',
            defaultModel: 'gemma3:12b',
            modelMap: ['claude-haiku-4-5' => 'gemma3:4b'],
            contextWindow: 16384,
        );
    }

    private function request(string $modelKey = 'claude-opus-5'): LlmRequest
    {
        return new LlmRequest(
            operation: 'question_generation_mcq',
            modelKey: $modelKey,
            systemPrompt: 'You write questions.',
            userPrompt: 'Passage: water boils at 100 °C.',
            outputSchema: [
                'type' => 'object',
                'required' => ['questions'],
                'properties' => ['questions' => ['type' => 'array']],
            ],
            params: ['max_tokens' => 4000],
        );
    }

    public function test_it_sends_the_schema_as_ollama_structured_output_and_reads_the_reply(): void
    {
        Http::fake([
            'ollama.test:11434/api/chat' => Http::response([
                'model' => 'gemma3:12b',
                'message' => ['role' => 'assistant', 'content' => '{"questions": [{"q": "At what temperature does water boil?"}]}'],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 120,
                'eval_count' => 40,
            ]),
        ]);

        $response = $this->client()->complete($this->request());

        $this->assertSame('At what temperature does water boil?', $response->data['questions'][0]['q']);
        $this->assertSame(120, $response->inputTokens);
        $this->assertSame(40, $response->outputTokens);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'http://ollama.test:11434/api/chat'
                && $body['model'] === 'gemma3:12b'
                && $body['stream'] === false
                // The prompt's schema constrains the reply.
                && $body['format']['required'] === ['questions']
                // Generation prompts need far more than Ollama's default context.
                && $body['options']['num_ctx'] === 16384
                && $body['options']['num_predict'] === 4000
                && $body['messages'][0]['role'] === 'system';
        });
    }

    public function test_registry_models_map_to_local_tags(): void
    {
        $this->assertSame('gemma3:4b', $this->client()->modelKey($this->request('claude-haiku-4-5')));
        $this->assertSame('gemma3:12b', $this->client()->modelKey($this->request('claude-opus-5')));
    }

    public function test_a_missing_model_says_how_to_fix_it(): void
    {
        Http::fake(['ollama.test:11434/api/chat' => Http::response(['error' => 'model "gemma3:12b" not found, try pulling it first'], 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ollama pull gemma3:12b');

        $this->client()->complete($this->request());
    }

    public function test_ollama_not_running_says_how_to_start_it(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ollama serve');

        $this->client()->complete($this->request());
    }

    public function test_non_json_text_is_a_schema_failure_not_a_crash(): void
    {
        Http::fake(['ollama.test:11434/api/chat' => Http::response(['message' => ['content' => 'Sure! Here are the questions:'], 'done' => true])]);

        $this->expectException(SchemaValidationException::class);

        $this->client()->complete($this->request());
    }

    public function test_embeddings_are_read_and_their_width_is_checked(): void
    {
        $provider = new OllamaEmbeddingProvider(app(Factory::class), 'http://ollama.test:11434', 'bge-m3', 4);

        Http::fake(['ollama.test:11434/api/embed' => Http::response(['embeddings' => [[0.1, 0.2, 0.3, 0.4], [0.5, 0.6, 0.7, 0.8]]])]);
        $this->assertSame([[0.1, 0.2, 0.3, 0.4], [0.5, 0.6, 0.7, 0.8]], $provider->embed(['a', 'b']));

        // A model of another width would corrupt the vector index.
        $narrow = new OllamaEmbeddingProvider(app(Factory::class), 'http://ollama.test:11434', 'nomic-embed-text', 1024);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bge-m3');
        $narrow->embed(['a', 'b']);
    }
}
