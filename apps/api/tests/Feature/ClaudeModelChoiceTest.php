<?php

declare(strict_types=1);

namespace Tests\Feature;

use Anthropic\Client as AnthropicClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Infrastructure\Ai\Providers\ClaudeClient;
use Tests\TestCase;

/**
 * Which Claude model each tier really calls, and what it is sent. Offline:
 * only the request is built, nothing goes to the API.
 */
final class ClaudeModelChoiceTest extends TestCase
{
    private function request(string $tierModel, array $params = []): LlmRequest
    {
        return new LlmRequest(
            operation: 'question_generation_mcq',
            modelKey: $tierModel,
            systemPrompt: 'system',
            userPrompt: 'user',
            outputSchema: ['type' => 'object'],
            params: $params,
        );
    }

    private function client(): ClaudeClient
    {
        return new ClaudeClient(new AnthropicClient(apiKey: 'test-key'));
    }

    public function test_the_tiers_default_to_the_low_cost_models(): void
    {
        $this->assertSame('claude-sonnet-5-5', $this->client()->requestFor($this->request('claude-opus-5'))['model']);
        $this->assertSame('claude-haiku-5-5', $this->client()->requestFor($this->request('claude-sonnet-5'))['model']);
        $this->assertSame('claude-haiku-5-5', $this->client()->requestFor($this->request('claude-haiku-4-5'))['model']);
    }

    public function test_a_tier_can_be_pointed_at_another_model(): void
    {
        config(['ai.providers.claude.model_map.claude-opus-5' => 'claude-opus-5-5']);

        $this->assertSame('claude-opus-5-5', $this->client()->requestFor($this->request('claude-opus-5'))['model']);
        // A model the map does not name is called as it is.
        $this->assertSame('claude-fable-5-1', $this->client()->requestFor($this->request('claude-fable-5-1'))['model']);
    }

    public function test_the_newer_models_are_never_sent_a_temperature(): void
    {
        $sent = $this->client()->requestFor($this->request('claude-opus-5', ['temperature' => 0.2]));

        $this->assertNull($sent['temperature'], 'Sonnet 5.5 rejects any non-default temperature.');
    }

    public function test_effort_is_capped_at_the_configured_ceiling_and_never_raised(): void
    {
        config(['ai.providers.claude.max_effort' => 'medium']);

        $high = $this->client()->requestFor($this->request('claude-opus-5', ['effort' => 'high']));
        $low = $this->client()->requestFor($this->request('claude-haiku-4-5', ['effort' => 'low']));

        $this->assertSame('medium', $high['outputConfig']['effort']);
        $this->assertSame('low', $low['outputConfig']['effort']);

        config(['ai.providers.claude.max_effort' => null]);
        $this->assertSame('high', $this->client()->requestFor($this->request('claude-opus-5', ['effort' => 'high']))['outputConfig']['effort']);
    }
}
