<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Domain\Ai\AiGateway;
use App\Domain\Ai\BudgetGuard;
use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\PromptRegistry;
use App\Domain\Ai\ResponseCache;
use App\Domain\Ai\SchemaValidator;
use App\Infrastructure\Ai\Providers\ClaudeClient;
use App\Infrastructure\Ai\Providers\FakeLlmClient;
use App\Infrastructure\Ai\Providers\OllamaClient;
use App\Infrastructure\Ai\Providers\OpenAiClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmClient::class, function (): LlmClient {
            return match (config('ai.driver')) {
                'fake' => new FakeLlmClient(config('ai.fixture_path')),
                'ollama' => new OllamaClient(
                    http: $this->app->make(HttpFactory::class),
                    baseUrl: (string) config('ai.providers.ollama.base_url'),
                    defaultModel: (string) config('ai.providers.ollama.default_model'),
                    modelMap: (array) config('ai.providers.ollama.model_map'),
                    timeout: (int) config('ai.providers.ollama.timeout_seconds'),
                    contextWindow: (int) config('ai.providers.ollama.context_window'),
                    keepAlive: config('ai.providers.ollama.keep_alive'),
                ),
                'openai' => new OpenAiClient(
                    http: $this->app->make(HttpFactory::class),
                    apiKey: (string) config('ai.providers.openai.api_key'),
                    defaultModel: (string) config('ai.providers.openai.default_model'),
                    modelMap: (array) config('ai.providers.openai.model_map'),
                    timeout: (int) config('ai.timeout_seconds'),
                ),
                'claude' => new ClaudeClient(
                    new AnthropicClient(apiKey: config('ai.providers.claude.api_key'))
                ),
                default => throw new RuntimeException(
                    'Unknown AI driver: '.config('ai.driver')
                ),
            };
        });

        $this->app->singleton(PromptRegistry::class);
        $this->app->singleton(SchemaValidator::class);
        $this->app->singleton(ResponseCache::class);
        $this->app->singleton(BudgetGuard::class);

        $this->app->singleton(AiGateway::class, fn ($app) => new AiGateway(
            $app->make(LlmClient::class),
            $app->make(PromptRegistry::class),
            $app->make(SchemaValidator::class),
            $app->make(ResponseCache::class),
            $app->make(BudgetGuard::class),
        ));
    }

    public function boot(): void
    {
        // A real provider call from the test suite is a bug, not a feature:
        // CI must never depend on network access or spend tokens.
        if ($this->app->environment('testing') && config('ai.driver') !== 'fake') {
            throw new RuntimeException(
                'The testing environment must use AI_DRIVER=fake.'
            );
        }
    }
}
