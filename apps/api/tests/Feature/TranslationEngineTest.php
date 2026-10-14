<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Exceptions\TranslationUnavailable;
use App\Domain\Translation\Glossary;
use App\Domain\Translation\ProtectedSpanMasker;
use App\Domain\Translation\QuestionTranslator;
use App\Domain\Translation\SubjectDomains;
use App\Domain\Translation\TranslationPrompts;
use App\Domain\Translation\TranslationRequester;
use App\Domain\Translation\TranslationStore;
use App\Domain\Translation\TranslationValidator;
use App\Models\AiJob;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\GlossaryTerm;
use App\Models\Language;
use App\Models\Question;
use App\Models\QuestionTranslation;
use App\Models\Source;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * A translated question is only worth serving if it still has the same right
 * answer. These pin the five checks one by one, the rules about what may
 * replace what, and the human review and glossary surfaces around them.
 *
 * The model is scripted: translation turns every English word into சொல்
 * (keeping tokens, and using TERMINOLOGY when told to), and verification
 * answers from a key the test registers — standing in for a solver that
 * understood the question.
 */
final class TranslationEngineTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $admin;

    private Exam $exam;

    private Source $source;

    private Language $english;

    private Language $tamil;

    /** @var array<string, string> master stem → correct option */
    private array $answers = [];

    /** @var list<LlmRequest> */
    public array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\AiModelSeeder::class);
        $this->seed(\Database\Seeders\PromptSeeder::class);

        $this->admin = $this->makeAdmin();
        $this->exam = $this->makeExam();
        $this->source = $this->makeSource('Science');
        $this->english = Language::byCode('en');
        $this->tamil = Language::byCode('ta');

        $this->model();
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @param  array<string, string>|null  $options
     */
    private function approved(int $index, ?string $stem = null, ?array $options = null, ?string $explanation = null): Question
    {
        $question = $this->makeQuestion($index, 'APPROVED', $this->source, $this->exam);

        $master = TenantContext::withoutTenancy(function () use ($question, $stem, $options, $explanation): QuestionTranslation {
            $master = QuestionTranslation::query()->where('question_id', $question->getKey())->where('is_master', true)->firstOrFail();
            $master->forceFill(array_filter([
                'question_text' => $stem,
                'option_a' => $options['A'] ?? null, 'option_b' => $options['B'] ?? null,
                'option_c' => $options['C'] ?? null, 'option_d' => $options['D'] ?? null,
                'explanation' => $explanation,
            ], static fn ($v): bool => $v !== null))->save();

            return $master->fresh();
        });

        $this->answers[$master->question_text] = ['A', 'B', 'C', 'D'][$index % 4];

        return $question;
    }

    private function term(string $source, string $target, string $kind = 'term'): void
    {
        TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->create([
            'source_language_id' => $this->english->getKey(),
            'target_language_id' => $this->tamil->getKey(),
            'source_term' => $source,
            'target_term' => $target,
            'kind' => $kind,
            'is_active' => true,
        ]));
    }

    /**
     * Scripts the model. Each callable may be replaced to break one check.
     *
     * @param  (Closure(array<string, mixed>, array<string, string>): array<string, mixed>)|null  $translate
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $verify
     */
    private function model(?Closure $translate = null, ?Closure $verify = null): void
    {
        $translate ??= fn (array $item, array $glossary): array => $this->tamilItem($item, $glossary);
        $verify ??= fn (array $item): array => [
            'ref' => $item['ref'],
            'back_translation' => $item['source']['question_text'],
            'meaning_score' => 0.95,
            'model_choice' => $this->answers[$item['source']['question_text']] ?? 'A',
            'confidence' => 0.9,
        ];

        $this->app->instance(LlmClient::class, new class($translate, $verify, $this) implements LlmClient
        {
            public function __construct(
                private readonly Closure $translate,
                private readonly Closure $verify,
                private readonly TranslationEngineTest $test,
            ) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->test->requests[] = $request;

                preg_match('/<ITEMS>(.*?)<\/ITEMS>/s', $request->userPrompt, $m);
                $items = json_decode(trim($m[1] ?? '[]'), true) ?: [];

                if ($request->operation === 'translation_verify') {
                    $data = ['verifications' => array_map($this->verify, $items)];
                } else {
                    // Each item carries the board terms that apply to it.
                    $data = ['translations' => array_map(
                        fn (array $item): array => ($this->translate)($item, (array) ($item['terminology'] ?? [])),
                        $items,
                    )];
                }

                return new LlmResponse($data, $request->modelKey, 10, 10);
            }

            public function modelKey(LlmRequest $request): string
            {
                return $request->modelKey;
            }
        });
    }

    /**
     * Every English word becomes சொல், glossary terms become their board
     * term, and tokens are left exactly where they were.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $glossary
     * @return array<string, mixed>
     */
    public function tamilItem(array $item, array $glossary, ?Closure $field = null): array
    {
        $field ??= function (string $text) use ($glossary): string {
            foreach ($glossary as $from => $to) {
                $text = str_ireplace($from, $to, $text);
            }

            $parts = preg_split('/(__PH_\d+__)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

            foreach ($parts as $i => $part) {
                if ($i % 2 === 0) {
                    $parts[$i] = (string) preg_replace('/[A-Za-z]+/', 'சொல்', $part);
                }
            }

            return implode('', $parts);
        };

        return [
            'ref' => $item['ref'],
            'question_text' => $field((string) $item['question_text']),
            'options' => array_map($field, (array) $item['options']),
            'explanation' => $field((string) $item['explanation']),
            'preserved_placeholders' => $item['placeholders'],
            'confidence' => 0.9,
        ];
    }

    /**
     * @param  list<Question>  $questions
     * @return array<string, mixed>
     */
    private function translate(array $questions): array
    {
        return TenantContext::withoutTenancy(fn () => app(QuestionTranslator::class)->translate(
            Question::query()->with(['options', 'translations'])->whereIn('id', array_map(fn (Question $q) => $q->getKey(), $questions))->get(),
            $this->tamil,
        ));
    }

    private function tamilOf(Question $question): ?QuestionTranslation
    {
        return TenantContext::withoutTenancy(fn () => app(TranslationStore::class)->latest($question->getKey(), $this->tamil->getKey()));
    }

    private function operations(string $operation): int
    {
        return count(array_filter($this->requests, fn (LlmRequest $r): bool => $r->operation === $operation));
    }

    // ------------------------------------------------------------ V1..V5

    public function test_a_translation_passing_every_check_is_validated_and_served(): void
    {
        $this->term('photosynthesis', 'ஒளிச்சேர்க்கை');
        $this->term('Cauvery', 'காவிரி', 'name');

        $question = $this->approved(
            1,
            'The Cauvery delta received 1,26,000 litres in 2019 while photosynthesis in CO₂ rich air rose by 25%.',
            ['A' => 'H2O', 'B' => '6CO2 + 6H2O -> C6H12O6 + 6O2', 'C' => '15/08/1947', 'D' => '3.5 km'],
        );

        $stats = $this->translate([$question]);

        $this->assertSame(1, $stats['validated']);

        $tamil = $this->tamilOf($question);

        $this->assertSame('AI_VALIDATED', $tamil->translation_status);
        $this->assertSame([], $tamil->validation_report['failures']);
        $this->assertGreaterThan(90, $tamil->quality_score);
        $this->assertSame($this->english->getKey(), $tamil->source_language_id);
        $this->assertSame(1, $tamil->translation_version);

        // Protected spans came back verbatim; the name in its fixed form; the
        // board's term where the English one was.
        foreach (['காவிரி', '1,26,000', '2019', 'CO₂', '25%', 'ஒளிச்சேர்க்கை'] as $expected) {
            $this->assertStringContainsString($expected, $tamil->question_text);
        }

        $this->assertSame(['A' => 'H2O', 'B' => '6CO2 + 6H2O -> C6H12O6 + 6O2', 'C' => '15/08/1947', 'D' => '3.5 km'], $tamil->optionMap());

        // And it is what a Tamil paper now reads.
        $served = TenantContext::withoutTenancy(fn () => Question::query()->with('translations')->find($question->getKey())->textIn($this->tamil->getKey()));
        $this->assertSame($tamil->getKey(), $served->getKey());
    }

    public function test_v1_a_lost_placeholder_or_a_changed_number_needs_review(): void
    {
        $dropped = $this->approved(1);
        $changed = $this->approved(2);

        $this->model(translate: function (array $item, array $glossary): array {
            $out = $this->tamilItem($item, $glossary);

            // marker1: option A's number token ("Alpha 1") vanishes.
            if (str_contains($item['question_text'], 'marker1')) {
                $out['options']['A'] = 'சொல்';
            }

            // marker2: a digit the masker left in place is altered.
            if (str_contains($item['question_text'], 'marker2')) {
                $out['question_text'] = str_replace('2', '9', $out['question_text']);
            }

            return $out;
        });

        $this->translate([$dropped, $changed]);

        foreach ([$dropped, $changed] as $question) {
            $tamil = $this->tamilOf($question);

            $this->assertSame('NEEDS_REVIEW', $tamil->translation_status);
            $this->assertSame(['V1_STRUCTURE'], $tamil->validation_report['failures']);
        }

        $this->assertStringContainsString('lost', implode(' ', $this->tamilOf($dropped)->validation_report['checks']['V1']['problems']));
        $this->assertContains('The numbers differ from the source.', $this->tamilOf($changed)->validation_report['checks']['V1']['problems']);
    }

    public function test_v2_a_board_term_replaced_by_a_synonym_needs_review(): void
    {
        $this->term('oxidation', 'ஆக்ஸிஜனேற்றம்');
        $question = $this->approved(2); // stem mentions "oxidation"

        // The translator ignores TERMINOLOGY.
        $this->model(translate: fn (array $item, array $glossary): array => $this->tamilItem($item, []));

        $this->translate([$question]);
        $tamil = $this->tamilOf($question);

        $this->assertSame('NEEDS_REVIEW', $tamil->translation_status);
        $this->assertSame(['V2_GLOSSARY'], $tamil->validation_report['failures']);
        $this->assertSame('ஆக்ஸிஜனேற்றம்', $tamil->validation_report['checks']['V2']['missing'][0]['expected']);

        // The prompt did carry the term: the failure is the model's.
        $this->assertStringContainsString('oxidation => ஆக்ஸிஜனேற்றம்', $this->requests[0]->userPrompt);
    }

    public function test_v3_a_low_meaning_score_needs_review(): void
    {
        $question = $this->approved(1);

        $this->model(verify: fn (array $item): array => [
            'ref' => $item['ref'], 'back_translation' => 'something else entirely',
            'meaning_score' => 0.6, 'model_choice' => $this->answers[$item['source']['question_text']], 'confidence' => 0.8,
        ]);

        $this->translate([$question]);
        $tamil = $this->tamilOf($question);

        $this->assertSame('NEEDS_REVIEW', $tamil->translation_status);
        $this->assertSame(['V3_MEANING'], $tamil->validation_report['failures']);
        $this->assertSame('something else entirely', $tamil->validation_report['back_translation']);
    }

    public function test_v4_a_different_answer_from_the_blind_solver_needs_review(): void
    {
        $question = $this->approved(1); // keyed B

        $this->model(verify: fn (array $item): array => [
            'ref' => $item['ref'], 'back_translation' => $item['source']['question_text'],
            'meaning_score' => 0.97, 'model_choice' => 'D', 'confidence' => 0.9,
        ]);

        $this->translate([$question]);
        $tamil = $this->tamilOf($question);

        $this->assertSame('NEEDS_REVIEW', $tamil->translation_status);
        $this->assertSame(['V4_ANSWER'], $tamil->validation_report['failures']);
        $this->assertSame('B', $tamil->validation_report['checks']['V4']['expected']);
        $this->assertSame('D', $tamil->validation_report['checks']['V4']['model_choice']);

        // The verifier was never shown the key — or the explanation that
        // would give it away.
        $verify = collect($this->requests)->firstWhere('operation', 'translation_verify');
        preg_match('/<ITEMS>(.*?)<\/ITEMS>/s', $verify->userPrompt, $m);
        $item = json_decode(trim($m[1]), true)[0];

        $this->assertSame(['ref', 'source', 'translated'], array_keys($item));
        $this->assertSame(['question_text', 'options'], array_keys($item['source']));
        $this->assertSame(['question_text', 'options'], array_keys($item['translated']));
    }

    public function test_v4_is_skipped_with_a_note_when_there_is_no_answer_key(): void
    {
        $fields = ['question_text' => 'Which gas is this?', 'options' => ['A' => 'X', 'B' => 'Y', 'C' => 'Z', 'D' => 'W'], 'explanation' => ''];
        $tamil = ['question_text' => 'இது எந்த வாயு?', 'options' => ['A' => 'ஒன்று', 'B' => 'இரண்டு', 'C' => 'மூன்று', 'D' => 'நான்கு'], 'explanation' => ''];

        $verdict = app(TranslationValidator::class)->validate(
            source: $fields,
            translated: $tamil,
            maskedTranslated: ['question_text' => $tamil['question_text']] + $tamil['options'] + ['explanation' => ''],
            owners: [],
            terms: [],
            verification: ['back_translation' => 'Which gas is this?', 'meaning_score' => 0.95, 'model_choice' => 'C', 'confidence' => 0.8],
            correctOption: null,
            targetScript: 'Taml',
        );

        $this->assertSame('AI_VALIDATED', $verdict['status']);
        $this->assertTrue($verdict['report']['checks']['V4']['skipped']);
        $this->assertStringContainsString('no answer key', implode(' ', $verdict['report']['notes']));
    }

    public function test_v5_text_left_in_english_needs_review(): void
    {
        $question = $this->approved(3);

        // "Translates" by returning the masked source untouched.
        $this->model(translate: fn (array $item, array $glossary): array => $this->tamilItem($item, $glossary, static fn (string $text): string => $text));

        $this->translate([$question]);
        $tamil = $this->tamilOf($question);

        $this->assertSame('NEEDS_REVIEW', $tamil->translation_status);
        $this->assertSame(['V5_LANGUAGE'], $tamil->validation_report['failures']);
        $this->assertSame(0.0, (float) $tamil->validation_report['checks']['V5']['script_ratio']);
    }

    // ------------------------------------------------- job, versions, store

    public function test_the_job_translates_an_exams_approved_questions_in_batches_of_ten(): void
    {
        foreach (range(0, 11) as $i) {
            $this->approved($i);
        }

        // Not approved, and already readable in Tamil: neither is touched.
        $this->makeQuestion(20, 'PENDING_REVIEW', $this->source, $this->exam);
        $done = $this->approved(21);
        TenantContext::withoutTenancy(fn () => QuestionTranslation::query()->create([
            'question_id' => $done->getKey(), 'language_id' => $this->tamil->getKey(), 'is_master' => false,
            'question_text' => 'கையால் மொழிபெயர்க்கப்பட்ட கேள்வி', 'option_a' => 'அ', 'option_b' => 'ஆ',
            'option_c' => 'இ', 'option_d' => 'ஈ', 'translation_status' => 'HUMAN_APPROVED',
        ]));

        $jobId = $this->actingAs($this->admin)
            ->postJson('/api/v1/translations', [
                'language_id' => $this->tamil->getKey(),
                'exam_id' => $this->exam->getKey(),
                'limit' => 50,
            ])
            ->assertStatus(202)
            ->json('data.job_id');

        $job = $this->actingAs($this->admin)->getJson("/api/v1/jobs/{$jobId}")->assertOk()->json('data');

        $this->assertSame('COMPLETED', $job['status']);
        // assertEquals: jsonb does not keep key order.
        $this->assertEquals(
            ['language' => 'ta', 'requested' => 12, 'translated' => 12, 'validated' => 12, 'needs_review' => 0, 'failed' => 0, 'skipped' => 0],
            $job['output'],
        );
        $this->assertStringContainsString('Batch 2/2', (string) $job['progress_message']);
        $this->assertSame(2, $this->operations('translation_ta'));
        $this->assertSame(2, $this->operations('translation_verify'));

        $this->assertSame('கையால் மொழிபெயர்க்கப்பட்ட கேள்வி', $this->tamilOf($done)->question_text);

        $stats = $this->actingAs($this->admin)
            ->getJson("/api/v1/translations/stats?language_id={$this->tamil->getKey()}&exam_id={$this->exam->getKey()}")
            ->assertOk()
            ->json('data');

        $this->assertSame(13, $stats['approved']);
        $this->assertSame(13, $stats['available']);
        $this->assertSame(1.0, (float) $stats['coverage']);
        $this->assertSame(12, $stats['by_status']['AI_VALIDATED']);
        $this->assertSame(1, $stats['by_status']['HUMAN_APPROVED']);
        $this->assertSame(0, $stats['needs_translation']);
    }

    public function test_a_human_approved_translation_is_never_overwritten(): void
    {
        $question = $this->approved(1);
        TenantContext::withoutTenancy(fn () => QuestionTranslation::query()->create([
            'question_id' => $question->getKey(), 'language_id' => $this->tamil->getKey(), 'is_master' => false,
            'question_text' => 'மனிதர் அங்கீகரித்த மொழிபெயர்ப்பு', 'option_a' => 'அ', 'option_b' => 'ஆ',
            'option_c' => 'இ', 'option_d' => 'ஈ', 'translation_status' => 'HUMAN_APPROVED',
        ]));

        $jobId = $this->actingAs($this->admin)
            ->postJson('/api/v1/translations', ['language_id' => $this->tamil->getKey(), 'question_ids' => [$question->getKey()]])
            ->assertStatus(202)
            ->json('data.job_id');

        $output = TenantContext::withoutTenancy(fn () => AiJob::query()->find($jobId)->output);

        $this->assertSame(1, $output['skipped']);
        $this->assertSame(0, $output['translated']);
        $this->assertSame(0, $this->operations('translation_ta'), 'An approved translation must not be paid for again.');

        // Even a direct write is refused.
        $refused = TenantContext::withoutTenancy(fn () => app(TranslationStore::class)->save(
            $question, $this->tamil->getKey(),
            ['question_text' => 'இயந்திரம்', 'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'explanation' => ''],
            'AI_VALIDATED', 90.0, [],
        ));

        $this->assertNull($refused);
        $this->assertSame('மனிதர் அங்கீகரித்த மொழிபெயர்ப்பு', $this->tamilOf($question)->question_text);
        $this->assertSame(1, TenantContext::withoutTenancy(fn () => QuestionTranslation::query()->where('question_id', $question->getKey())->where('language_id', $this->tamil->getKey())->count()));
    }

    public function test_a_stale_translation_is_retranslated_as_a_new_version(): void
    {
        $question = $this->approved(1);
        $this->translate([$question]);
        $first = $this->tamilOf($question);

        // The master was edited: ReviewWorkflow marks translations STALE.
        TenantContext::withoutTenancy(fn () => QuestionTranslation::query()->whereKey($first->getKey())->update(['translation_status' => 'STALE']));

        $this->translate([$question]);
        $second = $this->tamilOf($question);

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, $second->translation_version);
        $this->assertSame('AI_VALIDATED', $second->translation_status);
        $this->assertSame('STALE', TenantContext::withoutTenancy(fn () => $first->fresh()->translation_status));

        // The paper reads the new version, not the stale one.
        $fresh = TenantContext::withoutTenancy(fn () => Question::query()->with('translations')->find($question->getKey()));
        $this->assertSame($second->getKey(), $fresh->textIn($this->tamil->getKey())->getKey());
        $this->assertSame($second->getKey(), $fresh->translationIn($this->tamil->getKey())->getKey());
    }

    /**
     * Review finding C2: with the response cache on, a translation rejected
     * twice came back from the cache — byte-identical request, zero model
     * calls — and its cached verification passed it as AI_VALIDATED.
     */
    public function test_a_twice_rejected_translation_is_never_replayed_from_the_cache(): void
    {
        config(['ai.cache_enabled' => true]);

        $question = $this->approved(1);
        $calls = 0;

        // The first reply has one wording; every later reply has another.
        $this->model(translate: function (array $item, array $glossary) use (&$calls): array {
            $calls++;
            $out = $this->tamilItem($item, $glossary);

            if ($calls > 1) {
                $out['question_text'] .= ' மறுமுறை';
            }

            return $out;
        });

        $reject = fn () => TenantContext::withoutTenancy(fn () => QuestionTranslation::query()
            ->whereKey($this->tamilOf($question)->getKey())
            ->update(['translation_status' => 'REJECTED']));

        $this->translate([$question]);                        // v1
        $reject();
        $this->translate([$question]);                        // v2, new wording
        $this->assertSame(2, $this->tamilOf($question)->translation_version);
        $this->assertStringContainsString('rejected version 1', collect($this->requests)->where('operation', 'translation_ta')->last()->userPrompt);

        $reject();
        $stats = $this->translate([$question]);              // the model repeats v2's wording

        // The model was really asked again — not answered from the cache.
        $this->assertSame(3, $calls);
        $this->assertStringContainsString('rejected version 2', collect($this->requests)->where('operation', 'translation_ta')->last()->userPrompt);

        // And the repeat of rejected text was not stored, let alone served.
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(2, $this->tamilOf($question)->translation_version);
        $this->assertSame('REJECTED', $this->tamilOf($question)->translation_status);
        $this->assertFalse(TenantContext::withoutTenancy(fn () => QuestionTranslation::query()
            ->where('question_id', $question->getKey())->whereIn('translation_status', ['AI_VALIDATED', 'HUMAN_APPROVED'])->where('is_master', false)->exists()));
    }

    /**
     * Review finding C1: a master edited while the model was working was
     * stored as AI_VALIDATED with the old number and served.
     */
    public function test_a_master_edited_during_translation_is_discarded_not_served(): void
    {
        $bumped = $this->approved(1, 'The district had 1,25,000 voters in the last census survey of marker1.');
        $reworded = $this->approved(2, 'The river carried 4,500 boats across the delta region of marker2.');

        // Mid-call, the way ReviewWorkflow::applyEdits changes a master:
        // new text, and for one of them a new version number.
        $this->model(translate: function (array $item, array $glossary) use ($bumped, $reworded): array {
            static $edited = false;

            if (! $edited) {
                $edited = true;
                QuestionTranslation::query()->where('question_id', $bumped->getKey())->where('is_master', true)
                    ->update(['question_text' => 'The district had 1,00,000 voters in the last census survey of marker1.']);
                Question::query()->whereKey($bumped->getKey())->update(['current_version' => 2]);
                QuestionTranslation::query()->where('question_id', $reworded->getKey())->where('is_master', true)
                    ->update(['question_text' => 'The river carried 9,000 boats across the delta region of marker2.']);
            }

            return $this->tamilItem($item, $glossary);
        });

        $stats = $this->translate([$bumped, $reworded]);

        $this->assertSame(0, $stats['validated']);
        $this->assertSame(2, $stats['skipped']);
        $this->assertNull($this->tamilOf($bumped));
        $this->assertNull($this->tamilOf($reworded));

        // A Tamil paper falls back to the current master, never the old text.
        $served = TenantContext::withoutTenancy(fn () => Question::query()->with('translations')->find($bumped->getKey())->textIn($this->tamil->getKey()));
        $this->assertTrue($served->is_master);
        $this->assertStringContainsString('1,00,000', $served->question_text);
    }

    /** Review finding C1: each batch is loaded just before it is translated. */
    public function test_a_later_batch_is_translated_from_the_master_as_it_is_by_then(): void
    {
        // Eleven questions: ten in batch one, the lowest-quality one alone in
        // batch two.
        $late = $this->approved(0);

        foreach (range(1, 10) as $i) {
            $this->approved($i);
        }

        $newStem = 'Which edited process now governs 1,00,000 grains within marker0?';
        $this->answers[$newStem] = 'A';
        $calls = 0;

        $this->model(translate: function (array $item, array $glossary) use (&$calls, $late, $newStem): array {
            // The job runs as the admin's tenant, where row-level security
            // would quietly drop a write to a platform question.
            if (++$calls === 1) {
                TenantContext::withoutTenancy(function () use ($late, $newStem): void {
                    QuestionTranslation::query()->where('question_id', $late->getKey())->where('is_master', true)->update(['question_text' => $newStem]);
                    Question::query()->whereKey($late->getKey())->update(['current_version' => 2]);
                });
            }

            return $this->tamilItem($item, $glossary);
        });

        $jobId = $this->actingAs($this->admin)
            ->postJson('/api/v1/translations', ['language_id' => $this->tamil->getKey(), 'exam_id' => $this->exam->getKey(), 'limit' => 11])
            ->assertStatus(202)
            ->json('data.job_id');

        $output = TenantContext::withoutTenancy(fn () => AiJob::query()->find($jobId)->output);
        $this->assertSame(11, $output['validated']);

        $tamil = $this->tamilOf($late);
        $this->assertStringContainsString('1,00,000', $tamil->question_text);
        $this->assertSame(2, $tamil->source_question_version);
    }

    public function test_a_language_without_a_prompt_fails_clearly(): void
    {
        $telugu = Language::byCode('te');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/translations', ['language_id' => $telugu->getKey(), 'exam_id' => $this->exam->getKey()])
            ->assertStatus(422)
            ->assertJsonFragment(['detail' => "There is no translation prompt for Telugu yet. Seed an active prompt named 'translation_te' first."]);

        $this->expectException(TranslationUnavailable::class);
        $this->expectExceptionMessage("'translation_te'");

        app(TranslationPrompts::class)->resolve($telugu);
    }

    // ------------------------------------------------------------- review

    public function test_review_queue_shows_both_sides_and_approval_can_carry_edits(): void
    {
        $question = $this->approved(1);
        $this->model(verify: fn (array $item): array => [
            'ref' => $item['ref'], 'back_translation' => 'x', 'meaning_score' => 0.95, 'model_choice' => 'D', 'confidence' => 0.9,
        ]);
        $this->translate([$question]);

        $queue = $this->actingAs($this->admin)
            ->getJson("/api/v1/translations/review?language_id={$this->tamil->getKey()}")
            ->assertOk()
            ->json();

        $this->assertSame(1, $queue['meta']['total']);
        $item = $queue['data'][0];
        $this->assertSame(['V4_ANSWER'], $item['failures']);
        $this->assertSame('B', $item['correct_option']);
        $this->assertStringContainsString('marker1', $item['source']['question_text']);
        $this->assertStringContainsString('சொல்', $item['translation']['question_text']);
        $this->assertSame('en', $item['source_language']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/translations/{$item['id']}/decide", [
                'decision' => 'approve',
                'edits' => ['question_text' => 'திருத்தப்பட்ட கேள்வி marker1?', 'options' => ['B' => 'திருத்தப்பட்ட விடை 1']],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'HUMAN_APPROVED');

        $tamil = $this->tamilOf($question);

        $this->assertSame('HUMAN_APPROVED', $tamil->translation_status);
        $this->assertSame('திருத்தப்பட்ட கேள்வி marker1?', $tamil->question_text);
        $this->assertSame('திருத்தப்பட்ட விடை 1', $tamil->option_b);
        $this->assertSame($this->admin->getKey(), $tamil->reviewed_by);
        $this->assertNotNull($tamil->reviewed_at);
        $this->assertSame(['question_text', 'options.B'], $tamil->validation_report['review']['edited_fields']);

        $audit = TenantContext::withoutTenancy(fn () => AuditLog::query()->where('action', 'translation.approved')->first());
        $this->assertNotNull($audit);
        $this->assertSame($tamil->getKey(), $audit->auditable_id);

        // Approved now means served.
        $served = TenantContext::withoutTenancy(fn () => Question::query()->with('translations')->find($question->getKey())->textIn($this->tamil->getKey()));
        $this->assertSame('திருத்தப்பட்ட கேள்வி marker1?', $served->question_text);
    }

    public function test_reject_records_the_verdict_and_refuses_edits_and_stale_rows(): void
    {
        $question = $this->approved(1);
        $this->model(verify: fn (array $item): array => [
            'ref' => $item['ref'], 'back_translation' => 'x', 'meaning_score' => 0.5, 'model_choice' => 'B', 'confidence' => 0.9,
        ]);
        $this->translate([$question]);
        $tamil = $this->tamilOf($question);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/translations/{$tamil->getKey()}/decide", ['decision' => 'reject', 'edits' => ['question_text' => 'x']])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/translations/{$tamil->getKey()}/decide", ['decision' => 'reject', 'reason' => 'Wrong register'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');

        $rejected = $this->tamilOf($question);
        $this->assertSame('rejected', $rejected->review_status);
        $this->assertSame('Wrong register', $rejected->validation_report['review']['reason']);
        $this->assertTrue(TenantContext::withoutTenancy(fn () => AuditLog::query()->where('action', 'translation.rejected')->exists()));

        TenantContext::withoutTenancy(fn () => QuestionTranslation::query()->whereKey($tamil->getKey())->update(['translation_status' => 'STALE']));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/translations/{$tamil->getKey()}/decide", ['decision' => 'approve'])
            ->assertStatus(409);
    }

    // ------------------------------------------------------------ glossary

    public function test_glossary_import_creates_updates_and_skips(): void
    {
        $pair = ['source_language_id' => $this->english->getKey(), 'target_language_id' => $this->tamil->getKey()];

        $csv = implode("\n", [
            'source,target,kind,domain',
            'photosynthesis,ஒளிச்சேர்க்கை',
            'Cauvery,காவிரி,name,river',
            '"acids, bases",அமிலங்கள் காரங்கள்,term,chemistry',
            '# a comment line',
            'orphan',
            'x,y,verb',
            '',
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/glossary/import', $pair + ['csv' => $csv])
            ->assertOk()
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 2);

        $again = $this->actingAs($this->admin)
            ->postJson('/api/v1/glossary/import', $pair + ['csv' => "PHOTOSYNTHESIS,ஒளிச்சேர்க்கை\nCauvery,காவேரி,name,river"])
            ->assertOk()
            ->json('data');

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 1], array_intersect_key($again, array_flip(['created', 'updated', 'skipped'])));

        $cauvery = TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->where('source_term', 'Cauvery')->first());
        $this->assertSame('காவேரி', $cauvery->target_term);
        $this->assertSame('name', $cauvery->kind);
        $this->assertSame('river', $cauvery->domain);
        $this->assertTrue(TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->where('source_term', 'acids, bases')->exists()));
    }

    public function test_glossary_entries_can_be_added_edited_searched_and_deactivated(): void
    {
        $pair = ['source_language_id' => $this->english->getKey(), 'target_language_id' => $this->tamil->getKey()];

        $id = $this->actingAs($this->admin)
            ->postJson('/api/v1/glossary', $pair + ['source_term' => 'inertia', 'target_term' => 'நிலைமம்', 'domain' => 'Physics'])
            ->assertCreated()
            ->assertJsonPath('data.domain', 'physics')
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/glossary', $pair + ['source_term' => 'Inertia', 'target_term' => 'x', 'domain' => 'physics'])
            ->assertStatus(409);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/glossary/{$id}", ['notes' => 'TN Std 9 physics'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'TN Std 9 physics');

        $this->actingAs($this->admin)
            ->getJson('/api/v1/glossary?q=நிலை')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/glossary/{$id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Deactivated, not deleted — and out of the translator's hands.
        $this->assertTrue(TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->whereKey($id)->exists()));
        $this->actingAs($this->admin)->getJson('/api/v1/glossary?q=inertia')->assertJsonPath('meta.total', 0);
        $this->actingAs($this->admin)->getJson('/api/v1/glossary?q=inertia&include_inactive=1')->assertJsonPath('meta.total', 1);
        $this->assertSame([], app(Glossary::class)->entriesFor($this->english->getKey(), $this->tamil->getKey()));
        $this->assertTrue(TenantContext::withoutTenancy(fn () => AuditLog::query()->where('action', 'glossary.deactivated')->exists()));
    }

    public function test_the_seeded_glossary_is_large_and_reads_both_ways(): void
    {
        $this->seed(\Database\Seeders\GlossarySeeder::class);

        $counts = TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind'));

        $this->assertGreaterThanOrEqual(250, (int) $counts['term']);
        $this->assertGreaterThanOrEqual(30, (int) $counts['name']);

        // Tamil → English reads the same rows inverted.
        $reverse = collect(app(Glossary::class)->entriesFor($this->tamil->getKey(), $this->english->getKey()))->keyBy('source');
        $this->assertSame('photosynthesis', $reverse['ஒளிச்சேர்க்கை']['target']);
        $this->assertSame('Tamil Nadu', $reverse['தமிழ்நாடு']['target']);
        $this->assertSame('name', $reverse['தமிழ்நாடு']['kind']);

        // Review finding m1: corrected or withdrawn entries.
        $value = fn (string $source): ?string => TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->where('source_term', $source)->value('target_term'));
        $this->assertSame('பண மசோதா', $value('money bill'));
        $this->assertSame('சேரர்', $value('Chera'));
        $this->assertSame('சோழர்', $value('Chola'));
        $this->assertNull($value('troposphere'));
        $this->assertNull($value('stratosphere'));

        // Re-seeding never overwrites a reviewer's correction.
        TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->where('source_term', 'photosynthesis')->update(['target_term' => 'ஒளிச்சேர்க்கை (திருத்தம்)']));
        $this->seed(\Database\Seeders\GlossarySeeder::class);
        $this->assertSame('ஒளிச்சேர்க்கை (திருத்தம்)', TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->where('source_term', 'photosynthesis')->value('target_term')));
    }

    // ------------------------------------------- glossary matching (review)

    private function seededGlossary(): Glossary
    {
        $this->seed(\Database\Seeders\GlossarySeeder::class);

        return app(Glossary::class);
    }

    /**
     * @param  list<array{source: string, target: string}>  $names
     * @return list<string>  "original => restored" for each masked name
     */
    private function nameSpans(string $text, array $names): array
    {
        $masker = new ProtectedSpanMasker;
        $masker->reset();

        return array_values(array_map(
            static fn (array $e): string => "{$e['original']} => {$e['restore']}",
            array_filter($masker->mask($text, $names)['map'], static fn (array $e): bool => $e['kind'] === 'name'),
        ));
    }

    /** Review finding M1. */
    public function test_tamil_names_never_match_inside_ordinary_tamil_words(): void
    {
        $names = $this->seededGlossary()->namesFor($this->tamil->getKey(), $this->english->getKey());

        // சேர்மம் (compound), சேர்ந்தவர் (belonging to), நேரும் (happens).
        $this->assertSame([], $this->nameSpans('சேர்மம் ஒன்றைச் சேர்ந்தவர் நேரும் போது வந்தார்.', $names));

        // Real names, bare or with a case ending, are still pinned.
        $this->assertSame(
            ['நேரு => Nehru', 'சேரர்கள் => Cheras', 'சென்னை => Chennai'],
            $this->nameSpans('நேருவின் காலத்தில் சேரர்கள் பற்றி சென்னையில் பேசினார்.', $names),
        );
    }

    /** Review finding M2. */
    public function test_english_names_match_only_with_their_capitals(): void
    {
        $names = $this->seededGlossary()->namesFor($this->english->getKey(), $this->tamil->getKey());

        $this->assertSame([], $this->nameSpans('Which agent does not erode rocks?', $names));
        $this->assertSame(['Erode => ஈரோடு'], $this->nameSpans('Erode is known for turmeric.', $names));
    }

    /** Review finding M3, with the reviewer's own examples. */
    public function test_tamil_to_english_lookups_do_not_force_wrong_senses(): void
    {
        $glossary = $this->seededGlossary();
        $every = ['physics', 'chemistry', 'biology', 'environment', 'polity', 'geography', 'history', 'economy', 'aptitude'];
        $found = fn (string $text): array => array_column(
            $glossary->termsIn($text, $this->tamil->getKey(), $this->english->getKey(), $every),
            'target',
        );

        // Planets are not weekdays, and வெள்ளி is not only Friday.
        $this->assertSame([], array_values(array_intersect(
            ['Thursday', 'Saturday', 'Tuesday', 'Wednesday', 'Friday', 'silver'],
            $found('சூரியனுக்கு அருகிலுள்ள கோள் எது? (A) வியாழன் (B) சனி (C) செவ்வாய் (D) புதன். வெள்ளி ஒளிரும்.'),
        )));

        foreach ([
            'சொற்களை வரிசைப்படுத்துக' => 'tax',
            'வண்டி ஊருக்குச் செல்கிறது' => 'cell',
            'இந்தியாவின் கனிம வளங்கள்' => 'fruit',
            'அவர் சட்டத்தை நிறைவேற்றினார்' => 'mass',
            'ஆரம்பக் கல்வி இலவசம்' => 'radius',
            'ஆயிரங்கால் மண்டபம்' => 'soil',
            'அவர் சாதித்தது பெரியது' => 'caste',
        ] as $text => $wrong) {
            $this->assertNotContains($wrong, $found($text), "'{$wrong}' was forced on: {$text}");
        }

        // A real occurrence with its case ending still counts.
        $this->assertContains('photosynthesis', $found('ஒளிச்சேர்க்கையின் போது ஆக்ஸிஜன் வெளிவிடப்படுகிறது'));
    }

    /** Review finding M4. */
    public function test_english_terms_follow_the_questions_subject_and_phrases_beat_words(): void
    {
        $glossary = $this->seededGlossary();
        $terms = fn (string $text, ?array $subjects): array => array_column(
            $glossary->termsIn($text, $this->english->getKey(), $this->tamil->getKey(), $subjects),
            'target',
            'source',
        );

        $this->assertSame(['Air Force' => 'விமானப்படை'], $terms('The Indian Air Force was formed in 1932.', null));
        $this->assertSame(['adjournment motion' => 'ஒத்திவைப்புத் தீர்மானம்'], $terms('Who admits an adjournment motion?', ['polity']));
        $this->assertSame(['mass media' => 'மக்கள் ஊடகங்கள்'], $terms('Mass media shapes public opinion.', null));
        $this->assertSame(['solar cell' => 'சூரிய மின்கலம்'], $terms('A solar cell converts sunlight.', ['physics']));
        $this->assertSame(['Salt March' => 'உப்பு யாத்திரை'], $terms('Who led the Salt March?', ['history']));
        $this->assertSame(['equation' => 'சமன்பாடு'], $terms('Find the roots of the equation.', ['aptitude']));
        $this->assertArrayNotHasKey('President', $terms('Who was the President of the Indian National Congress in 1938?', ['history']));

        // A subject's single words apply in that subject only, and not at
        // all when the subject is unknown.
        $this->assertSame([], $terms('A force acts on the body in motion.', null));
        $this->assertEquals(['force' => 'விசை', 'motion' => 'இயக்கம்'], $terms('A force acts on the body in motion.', ['physics']));

        // A reviewer's polity sense is used for polity questions.
        TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->create([
            'source_language_id' => $this->english->getKey(), 'target_language_id' => $this->tamil->getKey(),
            'source_term' => 'motion', 'target_term' => 'தீர்மானம்', 'kind' => 'term', 'domain' => 'polity', 'is_active' => true,
        ]));
        $glossary->flush();

        $this->assertSame(['motion' => 'தீர்மானம்'], $terms('The motion was adopted by the House.', ['polity']));
        $this->assertSame(['motion' => 'இயக்கம்'], $terms('The motion was adopted by the House.', ['physics']));
    }

    /** Review finding M4: the subject comes from the question's topic. */
    public function test_a_questions_subject_is_read_from_its_topic(): void
    {
        $topic = $this->makeTopic('union-and-state-executive', 'Union and State Executive');
        $question = $this->approved(1);
        TenantContext::withoutTenancy(fn () => Question::query()->whereKey($question->getKey())->update(['canonical_topic_id' => $topic->getKey()]));

        $subjects = app(SubjectDomains::class);
        $domains = TenantContext::withoutTenancy(fn () => $subjects->forQuestions(Question::query()->whereKey($question->getKey())->get()));

        $this->assertSame(['polity'], $domains[$question->getKey()]);
        $this->assertSame(['physics'], $subjects->domainsIn('Physics: Force, Motion and Energy'));
        $this->assertNull($subjects->domainsIn('Miscellany'));
    }

    /** Review finding m3. */
    public function test_only_the_newest_version_decides_availability(): void
    {
        $question = $this->approved(1);

        TenantContext::withoutTenancy(function () use ($question): void {
            foreach ([[1, 'HUMAN_APPROVED'], [2, 'NEEDS_REVIEW']] as [$version, $status]) {
                QuestionTranslation::query()->create([
                    'question_id' => $question->getKey(), 'language_id' => $this->tamil->getKey(), 'is_master' => false,
                    'question_text' => "பதிப்பு {$version}", 'option_a' => 'அ', 'option_b' => 'ஆ', 'option_c' => 'இ', 'option_d' => 'ஈ',
                    'translation_version' => $version, 'translation_status' => $status,
                ]);
            }
        });

        [$available, $served] = TenantContext::withoutTenancy(fn () => [
            Question::query()->availableIn($this->tamil->getKey())->whereKey($question->getKey())->exists(),
            Question::query()->with('translations')->find($question->getKey())->textIn($this->tamil->getKey()),
        ]);

        // Selection and printing agree: the newest version is under review,
        // so the question is not available in Tamil and falls back.
        $this->assertFalse($available);
        $this->assertTrue($served->is_master);
    }

    /** Review finding m4. */
    public function test_an_organisation_admin_cannot_decide_shared_translations_or_change_the_glossary(): void
    {
        $orgAdmin = $this->makeAdmin('org@example.test', 'organization_admin', platformAdmin: false);
        $reviewer = $this->makeAdmin('reviewer@example.test', 'content_reviewer', platformAdmin: false);
        $question = $this->approved(1); // platform-owned
        $this->model(verify: fn (array $item): array => [
            'ref' => $item['ref'], 'back_translation' => 'x', 'meaning_score' => 0.95, 'model_choice' => 'D', 'confidence' => 0.9,
        ]);
        $this->translate([$question]);
        $tamil = $this->tamilOf($question);
        $pair = ['source_language_id' => $this->english->getKey(), 'target_language_id' => $this->tamil->getKey()];

        $this->actingAs($orgAdmin)->getJson("/api/v1/translations/review?language_id={$this->tamil->getKey()}")->assertOk();
        $this->actingAs($orgAdmin)->postJson("/api/v1/translations/{$tamil->getKey()}/decide", ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($orgAdmin)->getJson('/api/v1/glossary')->assertOk();
        $this->actingAs($orgAdmin)->postJson('/api/v1/glossary', $pair + ['source_term' => 'a', 'target_term' => 'b'])->assertForbidden();
        $this->actingAs($orgAdmin)->postJson('/api/v1/glossary/import', $pair + ['csv' => 'a,b'])->assertForbidden();
        $this->assertSame('NEEDS_REVIEW', $this->tamilOf($question)->translation_status);

        // A platform reviewer may.
        $this->actingAs($reviewer)->postJson("/api/v1/translations/{$tamil->getKey()}/decide", ['decision' => 'approve'])->assertOk();
        $this->assertSame('HUMAN_APPROVED', $this->tamilOf($question)->translation_status);
    }

    // ----------------------------------------------------------- requester

    public function test_the_requester_queues_once_per_exam_and_language(): void
    {
        Queue::fake();

        foreach (range(0, 4) as $i) {
            $this->approved($i);
        }

        $requester = app(TranslationRequester::class);

        [$first, $second] = TenantContext::withoutTenancy(fn () => [
            $requester->queueMissing($this->exam->getKey(), $this->tamil->getKey(), 3, $this->admin->getKey()),
            $requester->queueMissing($this->exam->getKey(), $this->tamil->getKey(), 3, $this->admin->getKey()),
        ]);

        $this->assertNotNull($first);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(3, $first->input['limit']);
        $this->assertSame(1, TenantContext::withoutTenancy(fn () => AiJob::query()->where('type', 'translation')->count()));

        // A second admin click while that job is queued gets the same job.
        $this->actingAs($this->admin)
            ->postJson('/api/v1/translations', ['language_id' => $this->tamil->getKey(), 'exam_id' => $this->exam->getKey()])
            ->assertStatus(202)
            ->assertJsonPath('data.job_id', $first->getKey())
            ->assertJsonPath('data.reused', true);

        // Nothing missing in English (the master language): nothing queued.
        $this->assertNull(TenantContext::withoutTenancy(fn () => $requester->queueMissing($this->exam->getKey(), $this->english->getKey(), 5, null)));
    }

    // -------------------------------------------------------- permissions

    public function test_a_student_cannot_review_translations_or_edit_the_glossary(): void
    {
        $student = $this->makeAdmin('student@example.test', 'student', platformAdmin: false);
        $question = $this->approved(1);
        $this->translate([$question]);
        $tamil = $this->tamilOf($question);
        $pair = ['source_language_id' => $this->english->getKey(), 'target_language_id' => $this->tamil->getKey()];

        $this->actingAs($student)->getJson("/api/v1/translations/review?language_id={$this->tamil->getKey()}")->assertForbidden();
        $this->actingAs($student)->postJson("/api/v1/translations/{$tamil->getKey()}/decide", ['decision' => 'reject'])->assertForbidden();
        $this->actingAs($student)->postJson('/api/v1/translations', ['language_id' => $this->tamil->getKey(), 'exam_id' => $this->exam->getKey()])->assertForbidden();
        $this->actingAs($student)->getJson('/api/v1/glossary')->assertForbidden();
        $this->actingAs($student)->postJson('/api/v1/glossary', $pair + ['source_term' => 'a', 'target_term' => 'b'])->assertForbidden();
        $this->actingAs($student)->postJson('/api/v1/glossary/import', $pair + ['csv' => 'a,b'])->assertForbidden();

        $this->assertSame('AI_VALIDATED', $this->tamilOf($question)->translation_status);
    }
}
