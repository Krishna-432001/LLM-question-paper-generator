<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Contracts\LlmClient;
use App\Domain\Ai\DTOs\LlmRequest;
use App\Domain\Ai\DTOs\LlmResponse;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Exceptions\TranslationUnavailable;
use App\Domain\Translation\Glossary;
use App\Domain\Translation\QuestionTranslator;
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
                    $glossary = [];

                    if (preg_match('/<TERMINOLOGY>(.*?)<\/TERMINOLOGY>/s', $request->userPrompt, $g)) {
                        foreach (preg_split('/\n/', trim($g[1])) ?: [] as $line) {
                            if (str_contains($line, ' => ')) {
                                [$from, $to] = explode(' => ', trim($line), 2);
                                $glossary[$from] = $to;
                            }
                        }
                    }

                    $data = ['translations' => array_map(fn (array $item): array => ($this->translate)($item, $glossary), $items)];
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

    public function test_a_rejected_translation_is_retried_with_the_rejection_in_the_request(): void
    {
        $question = $this->approved(1);
        $this->translate([$question]);
        TenantContext::withoutTenancy(fn () => QuestionTranslation::query()->whereKey($this->tamilOf($question)->getKey())->update(['translation_status' => 'REJECTED']));

        $this->requests = [];
        $this->translate([$question]);

        $this->assertSame(2, $this->tamilOf($question)->translation_version);
        $this->assertStringContainsString('rejected the previous translation', $this->requests[0]->userPrompt);
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

        // Re-seeding never overwrites a reviewer's correction.
        TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->where('source_term', 'photosynthesis')->update(['target_term' => 'ஒளிச்சேர்க்கை (திருத்தம்)']));
        $this->seed(\Database\Seeders\GlossarySeeder::class);
        $this->assertSame('ஒளிச்சேர்க்கை (திருத்தம்)', TenantContext::withoutTenancy(fn () => GlossaryTerm::query()->where('source_term', 'photosynthesis')->value('target_term')));
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
