<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\Translation\Exceptions\TranslationUnavailable;
use App\Models\Language;
use App\Models\PromptTemplate;

/**
 * Which prompt translates into which language.
 *
 * One prompt per TARGET language, named translation_{code}. The source is
 * whatever the question was mastered in, and it is always translated from
 * directly: a Tamil literature question bound for Telugu goes ta → te, never
 * ta → en → te, because every hop through a third language loses register
 * and compounds error.
 *
 * Adding a language is therefore a prompt, not code — and a language without
 * one fails here with a message that says what to seed, rather than deep in
 * the gateway with a generic one.
 */
final class TranslationPrompts
{
    public const VERIFY = 'translation_verify';

    public function nameFor(Language $target): string
    {
        return 'translation_'.$target->code;
    }

    public function exists(Language $target): bool
    {
        return PromptTemplate::query()
            ->where('name', $this->nameFor($target))
            ->whereHas('versions', fn ($q) => $q->where('status', 'active'))
            ->exists();
    }

    /** @throws TranslationUnavailable */
    public function resolve(Language $target): string
    {
        if (! $this->exists($target)) {
            throw new TranslationUnavailable(sprintf(
                "There is no translation prompt for %s. Seed an active prompt named '%s' before translating into it.",
                $target->name,
                $this->nameFor($target),
            ));
        }

        if (! PromptTemplate::query()->where('name', self::VERIFY)->exists()) {
            throw new TranslationUnavailable(
                "The '".self::VERIFY."' prompt is missing, so translations cannot be checked. Run the PromptSeeder."
            );
        }

        return $this->nameFor($target);
    }
}
