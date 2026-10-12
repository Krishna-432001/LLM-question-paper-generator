<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Prompts are data, versioned like migrations, never string literals in
 * controllers. A running job pins the version it resolved at start time.
 */
final class PromptRegistry
{
    private const CACHE_TTL = 300;

    public function resolve(string $name, ?string $pinnedVersionId = null): PromptVersion
    {
        if ($pinnedVersionId !== null) {
            $pinned = PromptVersion::query()->with('aiModel')->find($pinnedVersionId);

            if ($pinned !== null) {
                return $pinned;
            }
        }

        $version = Cache::remember(
            "prompt:active:{$name}",
            self::CACHE_TTL,
            function () use ($name): ?PromptVersion {
                $template = PromptTemplate::query()->where('name', $name)->first();

                return $template?->versions()
                    ->with('aiModel')
                    ->where('status', 'active')
                    ->first();
            }
        );

        if ($version === null) {
            throw new RuntimeException(
                "No active prompt version for '{$name}'. Seed it or promote a draft."
            );
        }

        return $version;
    }

    /**
     * Render {{variable}} placeholders. Values are inserted as plain text and
     * the surrounding prompt marks retrieved source material as untrusted
     * data, which is our defence against prompt injection from OCR'd books.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($variables): string {
                $value = data_get($variables, $m[1]);

                if ($value === null) {
                    return '';
                }

                return is_scalar($value)
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            },
            $template
        ) ?? $template;
    }

    public function flushCache(string $name): void
    {
        Cache::forget("prompt:active:{$name}");
    }
}
