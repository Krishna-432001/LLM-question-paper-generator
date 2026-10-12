<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai\Providers;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use Illuminate\Support\Facades\File;

/**
 * Deterministic, offline model client.
 *
 * Used in local development, CI and tests. CI blocks any merge whose tests
 * make a real provider call, so the entire pipeline — generation, validation,
 * translation — is exercised end to end at zero cost and with stable output.
 *
 * Resolution order for a response:
 *   1. A recorded fixture keyed by request hash (tests/Fixtures/ai/{hash}.json)
 *   2. A fixture named after the operation (tests/Fixtures/ai/{operation}.json)
 *   3. A synthesised object generated from the JSON Schema itself
 */
final class FakeLlmClient implements LlmClient
{
    public function __construct(private readonly string $fixturePath) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        if (($latency = (int) config('ai.fake_latency_ms', 0)) > 0) {
            usleep($latency * 1000);
        }

        $data = $this->fixture($request)
            ?? $this->grounded($request)
            ?? $this->synthesise($request->outputSchema);

        return new LlmResponse(
            data: $data,
            modelKey: $request->modelKey,
            inputTokens: (int) ceil((mb_strlen($request->systemPrompt.$request->userPrompt)) / 4),
            outputTokens: (int) ceil(mb_strlen(json_encode($data, JSON_THROW_ON_ERROR)) / 4),
            latencyMs: $latency,
            finishReason: 'stop',
        );
    }

    public function modelKey(LlmRequest $request): string
    {
        return $request->modelKey;
    }

    /**
     * Schema-shaped output is enough to exercise the plumbing, but not the
     * funnel: every synthesised question comes out with identical text, so
     * semantic dedup correctly rejects all but the first and an offline run
     * can never produce a usable bank.
     *
     * For the two operations that actually decide question quality the fake
     * therefore does real work against the passages in the prompt — distinct
     * stems drawn from distinct sentences, quotes copied verbatim, and an
     * answer check that genuinely re-reads the source.
     *
     * @return array<string, mixed>|null  null falls through to schema synthesis
     */
    private function grounded(LlmRequest $request): ?array
    {
        $properties = $request->outputSchema['properties'] ?? [];

        if (isset($properties['questions'])) {
            return $this->writeQuestions($request);
        }

        if (isset($properties['results']['items']['properties']['model_choice'])) {
            return $this->solveItems($request);
        }

        return null;
    }

    /**
     * One fill-in-the-blank question per usable sentence, each quoting the
     * sentence it came from.
     *
     * @return array<string, mixed>|null
     */
    private function writeQuestions(LlmRequest $request): ?array
    {
        $facts = [];

        foreach ($this->passages($request->userPrompt) as $ref => $text) {
            foreach ($this->sentences($text) as $sentence) {
                $term = $this->salientTerm($sentence);

                if ($term !== null) {
                    $facts[] = ['ref' => $ref, 'sentence' => $sentence, 'term' => $term];
                }
            }
        }

        if (count($facts) < 4) {
            return null;
        }

        $wanted = min(
            $this->requestedCount($request->userPrompt),
            count($facts),
            (int) ($request->outputSchema['properties']['questions']['maxItems'] ?? 30),
        );

        $questions = [];
        $wanted_formats = $this->requestedFormats($request->userPrompt);

        foreach (array_slice($facts, 0, $wanted) as $i => $fact) {
            // Distractors are other sentences' terms: plausible in register,
            // wrong in substance, and never equal to the answer.
            $pool = [];

            foreach ($facts as $j => $other) {
                if ($j !== $i && $other['term'] !== $fact['term'] && ! in_array($other['term'], $pool, true)) {
                    $pool[] = $other['term'];
                }
            }

            if (count($pool) < 3) {
                continue;
            }

            // Distractors closest in length to the answer look like real
            // choices; rotating the window by question index stops the same
            // three wrong answers appearing on every question in the paper.
            $answerLength = mb_strlen($fact['term']);

            usort($pool, static fn (string $a, string $b): int => abs(mb_strlen($a) - $answerLength) <=> abs(mb_strlen($b) - $answerLength));

            $near = array_slice($pool, 0, max(3, min(12, count($pool))));
            $offset = $i % count($near);

            $distractors = [];

            for ($k = 0; count($distractors) < 3 && $k < count($near); $k++) {
                $distractors[] = $near[($offset + $k) % count($near)];
                $distractors = array_values(array_unique($distractors));
            }

            if (count($distractors) < 3) {
                continue;
            }

            $letters = ['A', 'B', 'C', 'D'];
            $correctIndex = $i % 4;
            $values = array_slice($distractors, 0, 3);
            array_splice($values, $correctIndex, 0, [$fact['term']]);

            /** @var array<string, string> $options */
            $options = array_combine($letters, $values);

            // Rotate through the formats the caller asked for, so an offline
            // run exercises every renderer and not just the plain MCQ path.
            $format = $wanted_formats[$i % count($wanted_formats)];

            $questions[] = [
                'question_text' => $this->stemFor($format, $fact['sentence'], $fact['term']),
                'format' => $format,
                'options' => $options,
                'correct_option' => $letters[$correctIndex],
                'explanation' => 'The passage states: "'.mb_substr($fact['sentence'], 0, 700).'"',
                'difficulty' => ['easy', 'medium', 'hard'][$i % 3],
                'bloom_level' => ['remember', 'understand'][$i % 2],
                'topic' => $this->topic($request->userPrompt),
                'source_refs' => [$fact['ref']],
                // Verbatim, which is what the grounding checks demand.
                'supporting_quote' => mb_substr($fact['sentence'], 0, 300),
                'confidence' => 0.9,
            ];
        }

        return $questions === [] ? null : ['questions' => $questions];
    }

    /**
     * The independent re-solve. Each option is substituted back into the blank
     * and the result looked for in the source material — what a careful reader
     * does, and it disagrees with a mis-keyed question.
     *
     * @return array<string, mixed>|null
     */
    private function solveItems(LlmRequest $request): ?array
    {
        $items = $this->items($request->userPrompt);

        if ($items === []) {
            return null;
        }

        $material = $this->flatten($request->userPrompt);
        $results = [];

        foreach ($items as $item) {
            $stem = (string) ($item['question'] ?? '');
            $claimed = (string) ($item['claimed_answer'] ?? 'A');
            $choice = null;

            if (str_contains($stem, '______')) {
                foreach ((array) ($item['options'] ?? []) as $letter => $text) {
                    $filled = trim(str_replace(
                        'Fill in the blank:',
                        '',
                        $this->flatten(str_replace('______', (string) $text, $stem)),
                    ));

                    if ($filled !== '' && str_contains($material, $filled)) {
                        $choice = (string) $letter;
                        break;
                    }
                }
            }

            // Nothing to complete (a hand-written fixture, say): the fake has
            // no independent basis to disagree, so it does not invent one.
            $choice ??= $claimed;

            $results[] = [
                'ref' => $item['ref'] ?? '',
                'supported' => true,
                'support_span' => null,
                'answer_correct' => $choice === $claimed,
                'model_choice' => in_array($choice, ['A', 'B', 'C', 'D'], true) ? $choice : 'A',
                'single_correct' => true,
                'options_valid' => true,
                'suggested_difficulty' => 'medium',
                'issues' => $choice === $claimed ? [] : ['ANSWER_MISMATCH'],
                'confidence' => 0.9,
            ];
        }

        return ['results' => $results];
    }

    /** @return array<string, string>  "S1" => passage text */
    private function passages(string $prompt): array
    {
        if (! preg_match('/<SOURCE_MATERIAL>(.*?)<\/SOURCE_MATERIAL>/s', $prompt, $m)) {
            return [];
        }

        $parts = preg_split('/\[(S[0-9]{1,2})\]/', $m[1], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $passages = [];

        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            // Drop the two citation header lines that follow the marker.
            $body = preg_replace('/^[^\n]*\n[^\n]*\n/', '', ltrim($parts[$i + 1]));
            $passages[$parts[$i]] = trim((string) $body);
        }

        return $passages;
    }

    /** @return list<array<string, mixed>> */
    private function items(string $prompt): array
    {
        if (! preg_match('/<ITEMS>(.*?)<\/ITEMS>/s', $prompt, $m)) {
            return [];
        }

        $decoded = json_decode(trim($m[1]), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Script-agnostic word count. str_word_count() only understands ASCII. */
    private function wordCount(string $text): int
    {
        return preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $text);
    }

    /**
     * The longest word in a sentence, in any script. Short Indic tokens are
     * usually particles and case markers; the longest is the content word a
     * question can sensibly be built around.
     */
    private function longestToken(string $text): ?string
    {
        // \p{M} is required, not optional: an Indic word is letters PLUS
        // combining marks. Matching letters alone slices a word in the middle
        // of a grapheme, so blanking the match leaves orphaned vowel signs
        // stranded in the stem and the Tamil renders as nonsense.
        if (preg_match_all('/[\p{L}\p{M}\p{N}]{4,}/u', $text, $m) === 0) {
            return null;
        }

        usort($m[0], static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $m[0][0];
    }

    /** @return list<string> */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=\.)\s+/', $this->flatten($text)) ?: [];
        $out = [];

        foreach ($parts as $sentence) {
            $sentence = trim($sentence);

            // Long enough to carry a fact, short enough to quote in full.
            //
            // Counted with a Unicode pattern, NOT str_word_count(): that
            // function is ASCII-only and returns 0 for Tamil, Hindi or any
            // other Indic script, so every sentence in a state-board textbook
            // was silently discarded and the fake fell back to placeholder
            // text for the entire corpus this platform targets.
            if ($this->wordCount($sentence) >= 12 && mb_strlen($sentence) <= 290) {
                $out[] = $sentence;
            }
        }

        return $out;
    }

    /**
     * The most answer-like span in a sentence: a capitalised phrase if there
     * is one, otherwise the longest ordinary word.
     */
    private function salientTerm(string $sentence): ?string
    {
        // Skip the opening word, which is capitalised only by position.
        $tail = preg_replace('/^\S+\s+/', '', $sentence) ?? $sentence;

        if (preg_match_all('/\b[A-Z][a-z]{2,}(?:\s+[A-Z][a-z]{2,})*\b/', $tail, $m) && $m[0] !== []) {
            usort($m[0], static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

            return $m[0][0];
        }

        preg_match_all('/\b[a-z]{6,}\b/', $tail, $words);

        if ($words[0] === []) {
            // Nothing ASCII to work with — a Tamil, Hindi or Bengali sentence.
            // Fall back to the longest Unicode token, which for an Indic script
            // is a content word rather than a particle.
            return $this->longestToken($tail);
        }

        usort($words[0], static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $words[0][0];
    }


    /**
     * Only mcq4 keeps the blank the solver relies on; the other shapes state
     * the fact plainly and ask about it, which is how a real paper reads.
     */
    /**
     * Shapes the stem per format WITHOUT ever showing the intact sentence.
     *
     * The obvious construction — quote the source line, then ask about it —
     * puts the answer on the page, and the answer-leak check rejects it. So
     * every format is built around the blanked sentence alone.
     */
    private function stemFor(string $format, string $sentence, string $term): string
    {
        $blanked = str_replace($term, '______', $sentence);

        return match ($format) {
            'statement_based' => "Consider the following statement:
"
                ."1. {$blanked}
"
                .'Which term correctly completes statement 1?',
            'assertion_reason' => "Assertion (A): {$blanked}
"
                ."Reason (R): The passage states this directly.
"
                .'Which term correctly completes the Assertion?',
            'match_the_following' => "Match the statement with the term that completes it:
"
                ."1. {$blanked}
"
                .'Select the term that belongs at 1.',
            'true_false' => "Which term makes the following statement true?
".$blanked,
            default => 'Fill in the blank: '.$blanked,
        };
    }

    /** @return list<string> */
    private function requestedFormats(string $prompt): array
    {
        if (! preg_match('/^Formats to use[^:]*:\s*(.+)$/m', $prompt, $m)) {
            return ['mcq4'];
        }

        $formats = array_values(array_filter(array_map('trim', explode(',', $m[1]))));

        return $formats === [] ? ['mcq4'] : $formats;
    }

    private function requestedCount(string $prompt): int
    {
        return preg_match('/Generate\s+(\d+)\s+multiple-choice/i', $prompt, $m) ? (int) $m[1] : 10;
    }

    private function topic(string $prompt): string
    {
        return preg_match('/^Topic:\s*(.+)$/m', $prompt, $m)
            ? mb_substr(trim($m[1]), 0, 150)
            : 'General';
    }

    /** Collapses the line wrapping a PDF extract leaves behind. */
    private function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array<string, mixed>|null */
    private function fixture(LlmRequest $request): ?array
    {
        // A hash-keyed fixture is a recording of one exact request, so it is
        // always right to replay. An operation-named fixture is a blanket stub
        // for the whole operation: useful for deterministic tests, but outside
        // them it would return the same questions for every book uploaded.
        $names = app()->runningUnitTests()
            ? [$request->hash(), $request->operation]
            : [$request->hash()];

        foreach ($names as $name) {
            $path = "{$this->fixturePath}/{$name}.json";

            if (File::exists($path)) {
                /** @var array<string, mixed> $data */
                $data = json_decode(File::get($path), true, 64, JSON_THROW_ON_ERROR);

                return $data;
            }
        }

        return null;
    }

    /**
     * Builds the smallest object that satisfies the schema. This keeps the
     * fake honest: if a prompt's schema changes, the fake's output changes
     * with it and downstream code is exercised against the real contract.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function synthesise(array $schema): array
    {
        /** @var array<string, mixed> $value */
        $value = $this->valueFor($schema);

        return $value;
    }

    /** @param  array<string, mixed>  $schema */
    private function valueFor(array $schema, string $key = 'value'): mixed
    {
        if (isset($schema['enum'][0])) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? 'object';
        $type = is_array($type) ? ($type[0] ?? 'object') : $type;

        return match ($type) {
            'object' => $this->objectFor($schema),
            'array' => $this->arrayFor($schema),
            'integer' => (int) ($schema['minimum'] ?? 1),
            'number' => (float) ($schema['minimum'] ?? 0.9),
            'boolean' => true,
            'null' => null,
            default => $this->stringFor($schema, $key),
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function objectFor(array $schema): array
    {
        $out = [];
        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'] ?? [];
        /** @var list<string> $required */
        $required = $schema['required'] ?? array_keys($properties);

        foreach ($properties as $name => $definition) {
            if (in_array($name, $required, true)) {
                $out[$name] = $this->valueFor($definition, $name);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<mixed>
     */
    private function arrayFor(array $schema): array
    {
        $count = max(1, (int) ($schema['minItems'] ?? 1));
        /** @var array<string, mixed> $items */
        $items = $schema['items'] ?? ['type' => 'string'];

        return array_map(fn (): mixed => $this->valueFor($items), range(1, $count));
    }

    /** @param  array<string, mixed>  $schema */
    private function stringFor(array $schema, string $key): string
    {
        if (isset($schema['pattern']) && $schema['pattern'] === '^S[0-9]{1,2}$') {
            return 'S1';
        }

        $text = "[fake:{$key}] deterministic fixture text for local development";
        $min = (int) ($schema['minLength'] ?? 0);

        while (mb_strlen($text) < $min) {
            $text .= ' padding';
        }

        $max = (int) ($schema['maxLength'] ?? 0);

        return $max > 0 ? mb_substr($text, 0, $max) : $text;
    }
}
