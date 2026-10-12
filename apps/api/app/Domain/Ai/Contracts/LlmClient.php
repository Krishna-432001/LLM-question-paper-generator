<?php

declare(strict_types=1);

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;

/**
 * The only interface through which a language model may be reached.
 *
 * Implementations live in app/Infrastructure/Ai/Providers. A CI rule forbids
 * importing a provider SDK anywhere outside that directory, so every model
 * call is guaranteed to pass through AiGateway and be traced, costed and
 * budget-checked.
 */
interface LlmClient
{
    public function complete(LlmRequest $request): LlmResponse;

    /** Registry key of the model this client will use for the request. */
    public function modelKey(LlmRequest $request): string;
}
