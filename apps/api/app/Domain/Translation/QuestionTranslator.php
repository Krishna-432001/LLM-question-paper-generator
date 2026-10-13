<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\Exceptions\SchemaValidationException;
use App\Models\AiJob;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Translates a batch of approved questions into one language and decides
 * whether each result is fit to show a student.
 *
 *   mask → translate → unmask → verify → V1..V5 → store
 *
 * Two model calls per batch. The translation runs on the premium tier, the
 * verification on the cheap one — it only has to read both versions, solve
 * the translated question and say how far apart they are, and it is never
 * shown the answer key, so its pick is an independent witness.
 *
 * The source is always the question's own master text, whatever language
 * that is. Nothing is translated from another translation.
 */
final class QuestionTranslator
{
    private const OPTION_KEYS = ['A', 'B', 'C', 'D'];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly ProtectedSpanMasker $masker,
        private readonly Glossary $glossary,
        private readonly TranslationValidator $validator,
        private readonly TranslationStore $store,
        private readonly TranslationPrompts $prompts,
    ) {}

    /**
     * @param  Collection<int, Question>  $questions  approved, with options and translations loaded
     * @return array{translated: int, validated: int, needs_review: int, failed: int, skipped: int, errors: list<string>}
     */
    public function translate(Collection $questions, Language $target, ?AiJob $job = null): array
    {
        $promptName = $this->prompts->resolve($target);

        $stats = ['translated' => 0, 'validated' => 0, 'needs_review' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        // A batch can mix English- and Tamil-mastered questions. Each source
        // language goes in its own call, with its own glossary direction.
        foreach ($questions->groupBy('master_language_id') as $sourceLanguageId => $group) {
            $source = Language::query()->find($sourceLanguageId);

            if ($source === null || $source->getKey() === $target->getKey()) {
                $stats['skipped'] += $group->count();

                continue;
            }

            $result = $this->translateGroup($group->values(), $source, $target, $promptName, $job);

            foreach (['translated', 'validated', 'needs_review', 'failed', 'skipped'] as $key) {
                $stats[$key] += $result[$key];
            }

            $stats['errors'] = [...$stats['errors'], ...$result['errors']];
        }

        return $stats;
    }

    /**
     * @param  Collection<int, Question>  $questions
     * @return array{translated: int, validated: int, needs_review: int, failed: int, skipped: int, errors: list<string>}
     */
    private function translateGroup(
        Collection $questions,
        Language $source,
        Language $target,
        string $promptName,
        ?AiJob $job,
    ): array {
        $stats = ['translated' => 0, 'validated' => 0, 'needs_review' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        $names = $this->glossary->namesFor($source->getKey(), $target->getKey());
        $prepared = [];
        $promptTerms = [];

        foreach ($questions as $i => $question) {
            $master = $this->masterOf($question);

            if ($master === null) {
                $stats['skipped']++;

                continue;
            }

            $original = [
                'question_text' => (string) $master->question_text,
                'options' => array_map(
                    static fn (string $key): string => (string) $master->{'option_'.strtolower($key)},
                    array_combine(self::OPTION_KEYS, self::OPTION_KEYS),
                ),
                'explanation' => (string) $master->explanation,
            ];

            $masked = $this->masker->maskFields(
                ['question_text' => $original['question_text']]
                    + array_combine(self::OPTION_KEYS, array_values($original['options']))
                    + ['explanation' => $original['explanation']],
                $names,
            );

            $terms = $this->glossary->termsIn(
                implode("\n", [$original['question_text'], ...array_values($original['options']), $original['explanation']]),
                $source->getKey(),
                $target->getKey(),
            );

            foreach ($terms as $term) {
                $promptTerms[mb_strtolower($term['source'])] = $term;
            }

            $ref = 'Q'.($i + 1);
            $previous = $this->store->latest($question->getKey(), $target->getKey());

            $item = [
                'ref' => $ref,
                'question_text' => $masked['fields']['question_text'],
                'options' => array_intersect_key($masked['fields'], array_flip(self::OPTION_KEYS)),
                'explanation' => $masked['fields']['explanation'],
                'placeholders' => array_keys($masked['map']),
            ];

            // A reviewer threw the last attempt out. Saying so changes the
            // request, so the response cache cannot hand back the very text
            // that was rejected.
            if ($previous?->translation_status === 'REJECTED') {
                $item['note'] = 'A reviewer rejected the previous translation of this item. Translate it afresh, carefully.';
            }

            $prepared[$ref] = [
                'question' => $question,
                'original' => $original,
                'masked' => $masked,
                'terms' => $terms,
                'item' => $item,
            ];
        }

        if ($prepared === []) {
            return $stats;
        }

        $context = [
            'ai_job_id' => $job?->getKey(),
            'organization_id' => $job?->organization_id,
            'user_id' => $job?->user_id,
        ];

        try {
            $reply = $this->ai->structured($promptName, [
                'glossary' => $this->glossaryBlock(array_values($promptTerms)),
                'items' => array_values(array_column($prepared, 'item')),
                'source_language' => $source->name,
                'target_language' => $target->name,
            ], $context);
        } catch (SchemaValidationException $e) {
            // A malformed reply twice over (the gateway already tried one
            // repair) loses this batch, not the job: the next batch may be
            // fine, and the failure count says what happened.
            Log::warning('translation.batch_unusable', ['error' => $e->getMessage()]);
            $stats['failed'] += count($prepared);
            $stats['errors'][] = 'The model returned an unusable reply for a batch: '.mb_substr($e->getMessage(), 0, 300);

            return $stats;
        }

        $translations = [];

        foreach ((array) $reply->get('translations', []) as $row) {
            $ref = (string) ($row['ref'] ?? '');

            if (isset($prepared[$ref]) && ! isset($translations[$ref])) {
                $translations[$ref] = $row;
            }
        }

        $unmasked = [];
        $maskedOut = [];

        foreach ($translations as $ref => $row) {
            $map = $prepared[$ref]['masked']['map'];

            $maskedOut[$ref] = ['question_text' => (string) ($row['question_text'] ?? '')]
                + array_combine(self::OPTION_KEYS, array_map(
                    static fn (string $key): string => (string) ($row['options'][$key] ?? ''),
                    self::OPTION_KEYS,
                ))
                + ['explanation' => (string) ($row['explanation'] ?? '')];

            $unmasked[$ref] = [
                'question_text' => trim($this->masker->unmask($maskedOut[$ref]['question_text'], $map)),
                'options' => array_map(
                    fn (string $key): string => trim($this->masker->unmask($maskedOut[$ref][$key], $map)),
                    array_combine(self::OPTION_KEYS, self::OPTION_KEYS),
                ),
                'explanation' => trim($this->masker->unmask($maskedOut[$ref]['explanation'], $map)),
            ];
        }

        [$verifications, $verifyVersion] = $this->verify($prepared, $unmasked, $source, $target, $context);

        foreach ($prepared as $ref => $entry) {
            if (! isset($unmasked[$ref])) {
                $stats['failed']++;

                continue;
            }

            /** @var Question $question */
            $question = $entry['question'];

            $verdict = $this->validator->validate(
                source: $entry['original'],
                translated: $unmasked[$ref],
                maskedTranslated: $maskedOut[$ref],
                owners: $entry['masked']['owners'],
                terms: $entry['terms'],
                verification: $verifications[$ref] ?? null,
                correctOption: $question->correctOptionKey(),
                targetScript: (string) $target->script,
            );

            $report = $verdict['report'] + [
                'source_language' => $source->code,
                'target_language' => $target->code,
                'translation_prompt_version_id' => $reply->promptVersionId,
                'verify_prompt_version_id' => $verifyVersion,
                'protected_spans' => array_values(array_map(
                    static fn (array $span): array => ['kind' => $span['kind'], 'text' => $span['original']],
                    $entry['masked']['map'],
                )),
            ];

            $saved = $this->store->save(
                question: $question,
                languageId: $target->getKey(),
                fields: $unmasked[$ref],
                status: $verdict['status'],
                qualityScore: $verdict['quality_score'],
                report: $report,
                jobId: $job?->getKey(),
            );

            if ($saved === null) {
                // Became usable while this batch was in flight (a reviewer
                // approved a hand translation, say). Theirs stands.
                $stats['skipped']++;

                continue;
            }

            $stats['translated']++;
            $stats[$verdict['status'] === 'AI_VALIDATED' ? 'validated' : 'needs_review']++;
        }

        return $stats;
    }

    /**
     * The independent check. It reads the source and the translation but is
     * never given the answer key, and it answers the translated question
     * itself — so agreement with the key is evidence, not an echo.
     *
     * Its failure does not discard the translations: they are stored as
     * needing review, with the reason, rather than paid for twice.
     *
     * @param  array<string, array<string, mixed>>  $prepared
     * @param  array<string, array{question_text: string, options: array<string, string>, explanation: string}>  $unmasked
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, array<string, mixed>>, 1: ?string}
     */
    private function verify(array $prepared, array $unmasked, Language $source, Language $target, array $context): array
    {
        if ($unmasked === []) {
            return [[], null];
        }

        $items = [];

        foreach ($unmasked as $ref => $translated) {
            $original = $prepared[$ref]['original'];

            // Explanations stay out: they name the answer.
            $items[] = [
                'ref' => $ref,
                'source' => ['question_text' => $original['question_text'], 'options' => $original['options']],
                'translated' => ['question_text' => $translated['question_text'], 'options' => $translated['options']],
            ];
        }

        try {
            $reply = $this->ai->structured(TranslationPrompts::VERIFY, [
                'items' => $items,
                'source_language' => $source->name,
                'target_language' => $target->name,
            ], $context);
        } catch (Throwable $e) {
            Log::error('translation.verify_failed', ['error' => $e->getMessage()]);

            return [[], null];
        }

        $byRef = [];

        foreach ((array) $reply->get('verifications', []) as $row) {
            $ref = (string) ($row['ref'] ?? '');

            if (isset($unmasked[$ref]) && ! isset($byRef[$ref])) {
                $byRef[$ref] = $row;
            }
        }

        return [$byRef, $reply->promptVersionId];
    }

    private function masterOf(Question $question): ?QuestionTranslation
    {
        $question->loadMissing('translations');

        return $question->translations->firstWhere('is_master', true) ?? $question->master;
    }

    /** @param  list<array{source: string, target: string}>  $terms */
    private function glossaryBlock(array $terms): string
    {
        if ($terms === []) {
            return '(no listed terms occur in these items)';
        }

        return implode("\n", array_map(
            static fn (array $t): string => "{$t['source']} => {$t['target']}",
            $terms,
        ));
    }
}
