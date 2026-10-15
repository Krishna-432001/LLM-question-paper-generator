<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Documents\RenderDocumentAction;
use App\Domain\Documents\DocumentComposer;
use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\GeneratedDocument;
use App\Models\Language;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\QuestionTranslation;
use App\Models\Source;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * A paper in English, in Tamil, or in both.
 *
 * What is pinned here is that the language a student chose reaches every
 * surface the paper appears on — the runner, a resumed attempt, the
 * solutions and the printed PDF — and that a bilingual paper only ever
 * carries questions that can actually be read in both of its languages.
 */
final class PaperLanguageModeTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $student;

    private Exam $exam;

    private Source $source;

    private Language $english;

    private Language $tamil;

    /** @var list<Question> */
    private array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        Storage::fake('local');
        config(['pdf.driver' => 'fake', 'pdf.disk' => 'local']);

        $this->english = Language::byCode('en');
        $this->tamil = Language::byCode('ta');
        $this->student = $this->makeAdmin('student@example.test', 'student', platformAdmin: false);
        $this->exam = $this->makeExam('LANG_EXAM');
        $this->source = $this->makeSource('Bilingual Reader');

        foreach (range(0, 7) as $i) {
            $this->questions[] = $this->makeQuestion($i, 'APPROVED', $this->source, $this->exam, ['difficulty' => 'easy']);
        }

        TenantContext::withoutTenancy(function (): void {
            foreach ($this->questions as $i => $question) {
                QuestionTranslation::query()
                    ->where('question_id', $question->getKey())
                    ->where('is_master', true)
                    ->update(['explanation' => "English explanation marker{$i}."]);
            }
        });
    }

    public function test_a_tamil_test_is_served_and_printed_in_tamil(): void
    {
        $this->translateToTamil(range(0, 7));

        $test = $this->createTest(['language_id' => $this->tamil->getKey()])
            ->assertCreated()
            ->assertJsonPath('data.language_mode', 'single')
            ->assertJsonPath('data.languages.0.code', 'ta')
            ->assertJsonCount(1, 'data.languages')
            ->json('data');

        $attempt = $this->actingAs($this->student)
            ->postJson("/api/v1/tests/{$test['id']}/attempt")
            ->assertCreated()
            ->json('data');

        $this->assertSame('single', $attempt['language_mode']);

        foreach ($attempt['questions'] as $question) {
            $this->assertStringStartsWith('தமிழ் வினா', $question['question_text']);
            $this->assertSame(['ta'], array_keys($question['texts']));
            $this->assertSame($question['question_text'], $question['texts']['ta']['question_text']);
            $this->assertStringStartsWith('விடை', $question['texts']['ta']['options']['A']);
        }

        // The printed paper is in the test's language too. It used to print
        // the bank's English master whatever the test was.
        $composed = TenantContext::withoutTenancy(
            fn () => app(DocumentComposer::class)->questionPaper(Test::query()->findOrFail($test['id']), 'QP-TEST'),
        );

        $this->assertSame('ta', $composed['payload']['locale']);
        $this->assertSame(['ta'], $composed['payload']['languages']);

        foreach ($composed['payload']['questions'] as $question) {
            $this->assertStringStartsWith('தமிழ் வினா', $question['text']);
            $this->assertSame(['ta'], array_keys($question['texts']));
        }

        $this->assertStringContainsString('தமிழ் வினா', $composed['html']);
        $this->assertStringNotContainsString('specimen marker', $composed['html']);
        // The paper's own instructions follow the language as well.
        $this->assertStringContainsString('அறிவுரைகள்', $composed['html']);

        // And the PDF a student downloads is that paper, with no extra step.
        $documents = $this->actingAs($this->student)
            ->postJson("/api/v1/tests/{$test['id']}/documents", ['types' => ['question_paper']])
            ->assertCreated()
            ->json('data');

        $stored = TenantContext::withoutTenancy(fn () => GeneratedDocument::query()->findOrFail($documents[0]['id']));
        $this->assertStringStartsWith('தமிழ் வினா', $stored->render_payload['questions'][0]['text']);
    }

    public function test_a_bilingual_test_carries_both_languages_everywhere(): void
    {
        $this->translateToTamil(range(0, 7));

        $test = $this->createTest([
            'language_id' => $this->tamil->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->english->getKey(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.language_mode', 'bilingual')
            ->assertJsonPath('data.secondary_language_id', $this->english->getKey())
            ->json('data');

        $this->assertSame(['ta', 'en'], array_column($test['languages'], 'code'));

        $attempt = $this->actingAs($this->student)
            ->postJson("/api/v1/tests/{$test['id']}/attempt")
            ->assertCreated()
            ->json('data');

        $this->assertSame('bilingual', $attempt['language_mode']);
        $this->assertSame(['ta', 'en'], array_column($attempt['languages'], 'code'));

        foreach ($attempt['questions'] as $question) {
            // Tamil first because Tamil is the primary, as TNPSC prints it.
            $this->assertSame(['ta', 'en'], array_keys($question['texts']));
            $this->assertStringStartsWith('தமிழ் வினா', $question['texts']['ta']['question_text']);
            $this->assertStringContainsString('specimen marker', $question['texts']['en']['question_text']);
            $this->assertStringStartsWith('Alpha', $question['texts']['en']['options']['A']);
            // The old fields still carry the primary, for older clients.
            $this->assertSame($question['texts']['ta']['question_text'], $question['question_text']);
            $this->assertArrayNotHasKey('correct_option', $question);
        }

        // A reload must not turn a bilingual paper into a single-language one.
        $resumed = $this->actingAs($this->student)
            ->getJson("/api/v1/attempts/{$attempt['attempt_id']}")
            ->assertOk()
            ->assertJsonPath('data.language_mode', 'bilingual')
            ->json('data');

        $this->assertSame(['ta', 'en'], array_keys($resumed['questions'][0]['texts']));

        $this->actingAs($this->student)
            ->postJson("/api/v1/attempts/{$attempt['attempt_id']}/submit", [
                'answers' => array_map(fn ($q) => [
                    'test_question_id' => $q['test_question_id'],
                    'selected_option' => 'A',
                ], $attempt['questions']),
            ])
            ->assertOk();

        $solutions = $this->actingAs($this->student)
            ->getJson("/api/v1/results/{$attempt['attempt_id']}/solutions")
            ->assertOk()
            ->assertJsonPath('meta.language_mode', 'bilingual')
            ->json();

        foreach ($solutions['data'] as $solution) {
            $this->assertSame(['ta', 'en'], array_keys($solution['texts']));
            $this->assertStringStartsWith('தமிழ் விளக்கம்', $solution['texts']['ta']['explanation']);
            $this->assertStringStartsWith('English explanation', $solution['texts']['en']['explanation']);
        }

        $this->actingAs($this->student)
            ->getJson('/api/v1/me/tests')
            ->assertOk()
            ->assertJsonPath('data.0.language_mode', 'bilingual')
            ->assertJsonPath('data.0.languages', ['ta', 'en']);

        // The printed paper carries both, Tamil first, options in both.
        [$paper, $explanations] = TenantContext::withoutTenancy(function () use ($test): array {
            $model = Test::query()->findOrFail($test['id']);
            $composer = app(DocumentComposer::class);

            return [$composer->questionPaper($model, 'QP-TEST'), $composer->explanations($model, 'EX-TEST')];
        });

        $this->assertSame('bilingual', $paper['payload']['language_mode']);
        $this->assertSame(['ta', 'en'], $paper['payload']['languages']);

        foreach ($paper['payload']['questions'] as $question) {
            $this->assertSame(['ta', 'en'], array_keys($question['texts']));
            $this->assertStringStartsWith('தமிழ் வினா', $question['texts']['ta']['text']);
            $this->assertStringContainsString('specimen marker', $question['texts']['en']['text']);
            $this->assertCount(4, $question['texts']['en']['options']);
        }

        $html = $paper['html'];
        $this->assertStringContainsString('தமிழ் வினா', $html);
        $this->assertStringContainsString('specimen marker', $html);
        $this->assertStringContainsString('Alpha', $html);
        $this->assertLessThan(
            strpos($html, 'specimen marker'),
            strpos($html, 'தமிழ் வினா'),
            'The primary language is printed first.',
        );

        $item = $explanations['payload']['items'][0];
        $this->assertStringStartsWith('தமிழ் விளக்கம்', $item['texts']['ta']['explanation']);
        $this->assertStringStartsWith('English explanation', $item['texts']['en']['explanation']);
        $this->assertStringContainsString('English explanation', $explanations['html']);
    }

    public function test_bilingual_selection_skips_questions_missing_either_language(): void
    {
        $this->translateToTamil([0, 1, 2, 3, 4]);
        // A machine translation nobody has validated is not usable text.
        $this->translateToTamil([5], 'MACHINE');

        $usable = array_map(fn (int $i): string => $this->questions[$i]->getKey(), [0, 1, 2, 3, 4]);

        $testId = $this->createTest([
            'language_id' => $this->english->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->tamil->getKey(),
            'question_count' => 5,
        ])
            ->assertCreated()
            ->json('data.id');

        $chosen = TenantContext::withoutTenancy(
            fn () => TestQuestion::query()->where('test_id', $testId)->pluck('question_id')->all(),
        );

        $this->assertCount(5, $chosen);
        $this->assertEqualsCanonicalizing($usable, $chosen);

        // Asking for more than exist in both is refused with the counts, not
        // quietly shortened.
        $detail = $this->createTest([
            'language_id' => $this->english->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->tamil->getKey(),
            'question_count' => 6,
        ])
            ->assertStatus(422)
            ->json('detail');

        $this->assertStringContainsString('English: 8', $detail);
        $this->assertStringContainsString('Tamil: 5', $detail);
        $this->assertStringContainsString('both: 5', $detail);

        // A single-language Tamil paper is unaffected by the rule.
        $this->createTest(['language_id' => $this->tamil->getKey(), 'question_count' => 5])->assertCreated();
    }

    public function test_single_and_bilingual_renders_never_share_a_file(): void
    {
        $this->translateToTamil(range(0, 7));

        $testId = $this->createTest([
            'language_id' => $this->english->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->tamil->getKey(),
        ])->assertCreated()->json('data.id');

        TenantContext::withoutTenancy(function () use ($testId): void {
            $render = app(RenderDocumentAction::class);

            $bilingual = $render->execute(Test::query()->findOrFail($testId), 'question_paper');

            // Same test, same questions, same updated_at: only the mode moves.
            DB::table('tests')->where('id', $testId)->update([
                'language_mode' => 'single',
                'secondary_language_id' => null,
            ]);

            $single = $render->execute(Test::query()->findOrFail($testId), 'question_paper');
            $again = $render->execute(Test::query()->findOrFail($testId), 'question_paper');

            $this->assertNotSame($bilingual->content_hash, $single->content_hash);
            $this->assertNotSame($bilingual->getKey(), $single->getKey());
            $this->assertNotSame($bilingual->storage_path, $single->storage_path);
            $this->assertSame(['en', 'ta'], $bilingual->render_payload['languages']);
            $this->assertSame(['en'], $single->render_payload['languages']);

            // Reuse still works within a mode.
            $this->assertSame($single->getKey(), $again->getKey());
        });
    }

    public function test_a_book_becomes_a_bilingual_paper(): void
    {
        [$admin, $book] = $this->adminWithBook();
        $this->translateToTamil([0, 1, 2, 3, 4, 5]);

        $data = $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", [
                'question_count' => 10,
                'language_mode' => 'bilingual',
                'secondary_language_id' => $this->tamil->getKey(),
                'include_explanations' => true,
            ])
            ->assertCreated()
            ->json('data');

        // Eight approved, six of them in Tamil: six can go on a bilingual paper.
        $this->assertSame(6, $data['question_count']);
        $this->assertSame(10, $data['requested_count']);
        $this->assertSame('bilingual', $data['language_mode']);
        $this->assertSame(['en', 'ta'], $data['languages']);
        $this->assertSame(['primary' => 8, 'secondary' => 6, 'both' => 6], $data['availability']);

        foreach ($data['documents'] as $document) {
            $this->actingAs($admin)->get($document['download_url'])->assertOk();
        }

        $paper = TenantContext::withoutTenancy(fn () => GeneratedDocument::query()
            ->where('documentable_id', $data['test_id'])
            ->where('type', 'question_paper')
            ->firstOrFail());

        $this->assertSame(['en', 'ta'], $paper->render_payload['languages']);
        $this->assertSame(['en', 'ta'], array_keys($paper->render_payload['questions'][0]['texts']));

        // Tamil only, from the same book.
        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", [
                'question_count' => 10,
                'language_id' => $this->tamil->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 6)
            ->assertJsonPath('data.languages', ['ta']);

        // The old request shape still prints a single-language paper.
        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", ['question_count' => 3])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 3)
            ->assertJsonPath('data.language_mode', 'single')
            ->assertJsonPath('data.languages', ['en']);
    }

    public function test_a_book_with_no_translations_is_refused_with_counts(): void
    {
        [$admin, $book] = $this->adminWithBook();

        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", [
                'language_mode' => 'bilingual',
                'secondary_language_id' => $this->tamil->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'Tamil: 0') && str_contains($d, 'English: 8'));

        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", ['language_id' => $this->tamil->getKey()])
            ->assertStatus(422)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'available in Tamil'));
    }

    public function test_language_choices_are_validated(): void
    {
        $this->createTest(['language_mode' => 'bilingual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('secondary_language_id');

        $this->createTest([
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->english->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('secondary_language_id');

        $this->createTest(['language_mode' => 'trilingual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('language_mode');

        [$admin, $book] = $this->adminWithBook();

        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", ['language_mode' => 'bilingual'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('secondary_language_id');

        // The book is in English, so English cannot also be its second language.
        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", [
                'language_mode' => 'bilingual',
                'secondary_language_id' => $this->english->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'must differ'));
    }

    public function test_a_stale_translation_does_not_turn_a_tamil_paper_english(): void
    {
        $this->translateToTamil(range(0, 7));
        $testId = $this->createTest(['language_id' => $this->tamil->getKey()])->assertCreated()->json('data.id');
        $first = $this->firstQuestionOf($testId);

        // Any edit to the English master marks every translation STALE.
        $this->reviseTamil($first, ['translation_status' => 'STALE']);

        $paper = $this->render($testId, 'question_paper');
        $attempt = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")->assertCreated()->json('data');

        // The paper prints the Tamil it was assembled with, not the English
        // master filed under Tamil — and the runner shows the same words.
        $this->assertStringStartsWith('தமிழ் வினா', $paper->render_payload['questions'][0]['text']);
        $this->assertSame($paper->render_payload['questions'][0]['text'], $attempt['questions'][0]['question_text']);
    }

    public function test_a_revised_translation_rebuilds_the_paper_and_the_runner_agrees(): void
    {
        $this->translateToTamil(range(0, 7));
        $testId = $this->createTest(['language_id' => $this->tamil->getKey()])->assertCreated()->json('data.id');
        $first = $this->firstQuestionOf($testId);

        $before = $this->render($testId, 'question_paper');

        $this->reviseTamil($first, [
            'question_text' => 'திருத்திய தமிழ் வினா: எந்தச் செயல்முறை நடைபெறுகிறது?',
            'translation_status' => 'HUMAN_APPROVED',
        ]);

        $after = $this->render($testId, 'question_paper');
        $attempt = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")->assertCreated()->json('data');

        // The stored file is not reused once the words it printed changed.
        $this->assertNotSame($before->content_hash, $after->content_hash);
        $this->assertNotSame($before->getKey(), $after->getKey());
        $this->assertStringStartsWith('திருத்திய', $after->render_payload['questions'][0]['text']);
        $this->assertStringStartsWith('திருத்திய', $attempt['questions'][0]['question_text']);
    }

    public function test_retranslation_keeps_an_assembled_paper_as_it_was(): void
    {
        $this->translateToTamil(range(0, 7));
        $testId = $this->createTest([
            'language_id' => $this->english->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->tamil->getKey(),
        ])->assertCreated()->json('data.id');
        $first = $this->firstQuestionOf($testId);
        $original = $this->tamilRowOf($first);

        // Both languages are pinned to the rows the paper was assembled from.
        $this->assertSame($original->getKey(), $first->secondary_question_translation_id);

        // Re-translation writes a new version and retires the old one.
        TenantContext::withoutTenancy(function () use ($first, $original): void {
            QuestionTranslation::query()->create([
                'question_id' => $first->question_id,
                'language_id' => $this->tamil->getKey(),
                'is_master' => false,
                'translation_version' => 2,
                'question_text' => 'புதிய பதிப்பு வினா',
                'option_a' => 'புதிய அ', 'option_b' => 'புதிய ஆ', 'option_c' => 'புதிய இ', 'option_d' => 'புதிய ஈ',
                'translation_status' => 'HUMAN_APPROVED',
            ]);
            $original->forceFill(['translation_status' => 'STALE'])->save();
        });

        $paper = $this->render($testId, 'question_paper');
        $attempt = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")->assertCreated()->json('data');

        $this->assertSame($original->question_text, $paper->render_payload['questions'][0]['texts']['ta']['text']);
        $this->assertSame($original->question_text, $attempt['questions'][0]['texts']['ta']['question_text']);
    }

    public function test_a_language_with_nothing_printable_is_marked_unavailable_not_filled_with_english(): void
    {
        $this->translateToTamil(range(0, 7));
        $testId = $this->createTest([
            'language_id' => $this->english->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->tamil->getKey(),
        ])->assertCreated()->json('data.id');
        $first = $this->firstQuestionOf($testId);
        $english = TenantContext::withoutTenancy(fn () => QuestionTranslation::query()
            ->where('question_id', $first->question_id)->where('is_master', true)->value('question_text'));

        // A reviewer rejects the only Tamil this question had.
        $this->reviseTamil($first, ['translation_status' => 'REJECTED']);

        $question = $this->actingAs($this->student)->postJson("/api/v1/tests/{$testId}/attempt")
            ->assertCreated()->json('data.questions.0');

        $this->assertSame($english, $question['texts']['en']['question_text']);
        $this->assertTrue($question['texts']['en']['available']);
        $this->assertNull($question['texts']['ta']['question_text']);
        $this->assertFalse($question['texts']['ta']['available']);

        $paper = $this->render($testId, 'question_paper');
        $this->assertFalse($paper->render_payload['questions'][0]['texts']['ta']['available']);
        $this->assertNull($paper->render_payload['questions'][0]['texts']['ta']['text']);

        // In a Tamil-only paper the gap is marked in print rather than
        // replaced by English under a Tamil heading.
        $tamilOnly = $this->createTest(['language_id' => $this->tamil->getKey(), 'question_count' => 5])
            ->assertCreated()->json('data.id');
        $rejected = $this->firstQuestionOf($tamilOnly);
        $this->reviseTamil($rejected, ['translation_status' => 'REJECTED']);

        $composed = TenantContext::withoutTenancy(
            fn () => app(DocumentComposer::class)->questionPaper(Test::query()->findOrFail($tamilOnly), 'QP-TEST'),
        );
        $master = TenantContext::withoutTenancy(fn () => QuestionTranslation::query()
            ->where('question_id', $rejected->question_id)->where('is_master', true)->value('question_text'));

        $this->assertNull($composed['payload']['questions'][0]['text']);
        $this->assertStringContainsString('தமிழ் மொழிபெயர்ப்பு கிடைக்கவில்லை', $composed['html']);
        $this->assertStringNotContainsString(e($master), $composed['html']);
    }

    public function test_language_ids_differing_only_in_letter_case_are_the_same_language(): void
    {
        $this->translateToTamil(range(0, 7));

        // Postgres reads these as one uuid; PHP used to read them as two, and
        // the request then failed the database's own check as a 500.
        $this->createTest([
            'language_mode' => 'bilingual',
            'secondary_language_id' => strtoupper($this->english->getKey()),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('secondary_language_id');

        $this->createTest([
            'language_id' => strtoupper($this->english->getKey()),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->tamil->getKey(),
        ])->assertCreated();

        [$admin, $book] = $this->adminWithBook();

        $this->actingAs($admin)
            ->postJson("/api/v1/sources/{$book->getKey()}/paper", [
                'language_mode' => 'bilingual',
                'secondary_language_id' => strtoupper($this->english->getKey()),
            ])
            ->assertStatus(422)
            ->assertJsonPath('detail', fn (string $d): bool => str_contains($d, 'must differ'));
    }

    public function test_long_questions_may_break_across_pages_and_the_first_starts_on_page_one(): void
    {
        $this->translateToTamil(range(0, 7));
        $testId = $this->createTest(['question_count' => 5])->assertCreated()->json('data.id');
        $long = $this->firstQuestionOf($testId, sequence: 3);

        TenantContext::withoutTenancy(fn () => QuestionTranslation::query()
            ->where('question_id', $long->question_id)->where('is_master', true)
            ->update(['question_text' => implode("\n", array_map(
                fn (int $n): string => "Statement {$n}: a long passage line describing the specimen in considerable detail for reading.",
                range(1, 40),
            ))]));

        $html = TenantContext::withoutTenancy(
            fn () => app(DocumentComposer::class)->questionPaper(Test::query()->findOrFail($testId), 'QP-TEST')['html'],
        );

        preg_match_all('/<table class="(q[^"]*)"[^>]*>(.*?)<\/table>/s', $html, $items, PREG_SET_ORDER);
        $classes = array_map(fn (array $m): string => $m[1], $items);

        $this->assertCount(5, $items);
        // The first question starts under the instructions, not on page two.
        $this->assertSame('q', $classes[0]);
        // A question longer than half a page may break between its rows...
        $this->assertSame('q', $classes[2]);
        // ...and has rows to break between: one per line of the passage.
        $this->assertGreaterThanOrEqual(40, substr_count($items[2][2], '<tr>'));
        // Short questions are still kept on one page.
        $this->assertSame('q keep', $classes[1]);
        $this->assertSame('q keep', $classes[4]);

        // And the renderer lets a kept question that cannot fit a page break
        // rather than shrinking it.
        $config = (new \ReflectionMethod(\App\Infrastructure\Pdf\MpdfRenderer::class, 'config'))
            ->invoke(new \App\Infrastructure\Pdf\MpdfRenderer(sys_get_temp_dir()), []);
        $this->assertTrue($config['tableMinSizePriority']);
    }

    public function test_key_explanation_and_docx_headings_follow_the_paper_language(): void
    {
        $this->translateToTamil(range(0, 7));
        $tamil = $this->createTest(['language_id' => $this->tamil->getKey(), 'duration_minutes' => 30])
            ->assertCreated()->json('data.id');
        $both = $this->createTest([
            'language_id' => $this->tamil->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->english->getKey(),
        ])->assertCreated()->json('data.id');

        $key = $this->render($tamil, 'answer_key');
        $explanations = $this->render($tamil, 'explanations');

        $this->assertStringEndsWith('விடைக்குறிப்பு', $key->title);
        $this->assertStringNotContainsString('Answer Key', $key->title);
        $this->assertStringEndsWith('விளக்கங்கள்', $explanations->title);
        $this->assertStringEndsWith('விடைக்குறிப்பு / Answer Key', $this->render($both, 'answer_key')->title);

        $docx = $this->render($tamil, 'question_paper', 'docx');
        $xml = $this->docxText(Storage::disk('local')->get($docx->storage_path));

        $this->assertStringContainsString('நேரம்', $xml);
        $this->assertStringContainsString('விடைக்குறிப்பு', $xml);
        $this->assertStringNotContainsString('Time:', $xml);
    }

    public function test_the_json_export_states_its_languages_and_their_order(): void
    {
        $this->translateToTamil(range(0, 7));
        $testId = $this->createTest([
            'language_id' => $this->tamil->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->english->getKey(),
        ])->assertCreated()->json('data.id');

        $json = json_decode(
            Storage::disk('local')->get($this->render($testId, 'question_paper', 'json')->storage_path),
            true,
        );

        $this->assertSame('bilingual', $json['language_mode']);
        $this->assertSame(['ta', 'en'], $json['languages']);
    }

    public function test_the_excel_export_carries_both_languages_in_their_own_columns(): void
    {
        $this->translateToTamil(range(0, 7));
        $testId = $this->createTest([
            'language_id' => $this->tamil->getKey(),
            'language_mode' => 'bilingual',
            'secondary_language_id' => $this->english->getKey(),
        ])->assertCreated()->json('data.id');

        $path = tempnam(sys_get_temp_dir(), 'ef-test-').'.xlsx';
        file_put_contents($path, Storage::disk('local')->get($this->render($testId, 'question_paper', 'xlsx')->storage_path));

        try {
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

            // Columns A–H unchanged for existing readers; English follows.
            $this->assertSame(
                ['#', 'Question', 'A', 'B', 'C', 'D', 'Answer', 'Marks', 'Question (en)', 'A (en)', 'B (en)', 'C (en)', 'D (en)'],
                $sheet->rangeToArray('A1:M1')[0],
            );

            // The primary stem is the Tamil one, the extra columns English.
            $this->assertMatchesRegularExpression('/[\x{0B80}-\x{0BFF}]/u', (string) $sheet->getCell('B2')->getValue());
            $this->assertDoesNotMatchRegularExpression('/[\x{0B80}-\x{0BFF}]/u', (string) $sheet->getCell('I2')->getValue());
            $this->assertNotSame('', (string) $sheet->getCell('J2')->getValue());
        } finally {
            @unlink($path);
        }
    }

    private function render(string $testId, string $type, string $format = 'pdf'): GeneratedDocument
    {
        // A fresh model each time, as a request would load it.
        return TenantContext::withoutTenancy(
            fn () => app(RenderDocumentAction::class)->execute(Test::query()->findOrFail($testId), $type, null, $format),
        );
    }

    private function firstQuestionOf(string $testId, int $sequence = 1): TestQuestion
    {
        return TenantContext::withoutTenancy(
            fn () => TestQuestion::query()->where('test_id', $testId)->where('sequence', $sequence)->firstOrFail(),
        );
    }

    private function tamilRowOf(TestQuestion $testQuestion): QuestionTranslation
    {
        return TenantContext::withoutTenancy(fn () => QuestionTranslation::query()
            ->where('question_id', $testQuestion->question_id)
            ->where('language_id', $this->tamil->getKey())
            ->orderBy('translation_version')
            ->firstOrFail());
    }

    /** @param  array<string, mixed>  $attributes */
    private function reviseTamil(TestQuestion $testQuestion, array $attributes): void
    {
        $row = $this->tamilRowOf($testQuestion);

        TenantContext::withoutTenancy(fn () => $row->forceFill($attributes)->save());
    }

    private function docxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ef-docx-test-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    /** @param  array<string, mixed>  $overrides */
    private function createTest(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)->postJson('/api/v1/tests', $overrides + [
            'exam_id' => $this->exam->getKey(),
            'language_id' => $this->english->getKey(),
            'type' => 'daily',
            'question_count' => 5,
            'difficulty_mix' => ['easy' => 1.0],
        ]);
    }

    /**
     * A Tamil rendering for the given questions. Validated by default — the
     * state a translation reaches before it may appear on a paper.
     *
     * @param  list<int>  $indexes
     */
    private function translateToTamil(array $indexes, string $status = 'AI_VALIDATED'): void
    {
        TenantContext::withoutTenancy(function () use ($indexes, $status): void {
            foreach ($indexes as $i) {
                QuestionTranslation::query()->create([
                    'question_id' => $this->questions[$i]->getKey(),
                    'language_id' => $this->tamil->getKey(),
                    'is_master' => false,
                    'source_language_id' => $this->english->getKey(),
                    'question_text' => "தமிழ் வினா எண் {$i}: இந்த மாதிரியில் எந்தச் செயல்முறை நடைபெறுகிறது?",
                    'option_a' => "விடை அ {$i}",
                    'option_b' => "விடை ஆ {$i}",
                    'option_c' => "விடை இ {$i}",
                    'option_d' => "விடை ஈ {$i}",
                    'explanation' => "தமிழ் விளக்கம் {$i}.",
                    'translation_status' => $status,
                ]);
            }
        });
    }

    /**
     * An admin and a book in their own organisation, holding the eight
     * approved questions made in setUp.
     *
     * @return array{0: User, 1: Source}
     */
    private function adminWithBook(): array
    {
        $admin = $this->makeAdmin('paper-admin@example.test');

        TenantContext::withoutTenancy(function () use ($admin): void {
            $orgId = OrganizationUser::query()->where('user_id', $admin->getKey())->value('organization_id');
            $this->source->forceFill(['organization_id' => $orgId])->save();
        });

        return [$admin, $this->source];
    }
}
