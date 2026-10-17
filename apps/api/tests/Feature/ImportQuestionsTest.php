<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Language;
use App\Models\Question;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/** questions:import — written questions, with their Tamil, into the review queue. */
final class ImportQuestionsTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $this->source = $this->makeSource('Std 10 Social Science Term-Full (English)');
    }

    /** @param  list<array<string, mixed>>  $questions */
    private function file(array $questions): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ef-questions-');
        file_put_contents($path, json_encode([
            'exam' => 'TNPSC_G4',
            'source' => $this->source->title,
            'breadcrumb' => 'Std 10 Social Science — Civics Unit 1: Indian Constitution',
            'questions' => $questions,
        ], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** @return array<string, mixed> */
    private function question(array $overrides = []): array
    {
        return $overrides + [
            'topic' => 'constitution-of-india',
            'format' => 'mcq4',
            'difficulty' => 'easy',
            'bloom' => 'remember',
            'page' => 240,
            'quote' => 'The drafted Constitution came into force on 26th January, 1950.',
            'answer' => 'B',
            'en' => [
                'q' => 'On which date did the Constitution of India come into force?',
                'options' => ['26 November 1949', '26 January 1950', '15 August 1947', '9 December 1946'],
                'explanation' => 'It was adopted on 26 November 1949 and came into force on 26 January 1950, Republic Day.',
            ],
            'ta' => [
                'q' => 'இந்திய அரசியலமைப்புச் சட்டம் எந்த நாளில் நடைமுறைக்கு வந்தது?',
                'options' => ['1949 நவம்பர் 26', '1950 ஜனவரி 26', '1947 ஆகஸ்ட் 15', '1946 டிசம்பர் 9'],
                'explanation' => '1949 நவம்பர் 26இல் ஏற்றுக்கொள்ளப்பட்டு, 1950 ஜனவரி 26இல் நடைமுறைக்கு வந்தது.',
            ],
        ];
    }

    public function test_a_question_lands_in_the_review_queue_with_its_tamil_page_and_topic(): void
    {
        $this->artisan('questions:import', ['file' => $this->file([$this->question()])])
            ->expectsOutputToContain('1 added to the review queue')
            ->assertSuccessful();

        TenantContext::withoutTenancy(function (): void {
            $question = Question::query()->with(['options', 'translations', 'sources'])->sole();

            $this->assertSame('PENDING_REVIEW', $question->status);
            $this->assertSame('B', $question->correctOptionKey());
            $this->assertSame('constitution-of-india', DB::table('canonical_topics')->where('id', $question->canonical_topic_id)->value('slug'));

            $tamil = $question->translations->firstWhere('language_id', Language::byCode('ta')->getKey());
            $this->assertSame('1950 ஜனவரி 26', $tamil->option_b);
            $this->assertSame('AI_VALIDATED', $tamil->translation_status);
            $this->assertFalse($tamil->is_master);

            $citation = $question->sources->sole();
            $this->assertSame([240, $this->source->getKey()], [$citation->page_start, $citation->source_id]);
            $this->assertStringContainsString('26th January, 1950', $citation->supporting_quote);

            $this->assertTrue(DB::table('question_exam_mappings')->where('question_id', $question->getKey())->where('is_active', true)->exists());
            $this->assertSame('pending', DB::table('reviews')->where('reviewable_id', $question->getKey())->value('status'));
        });
    }

    public function test_a_tamil_language_question_is_written_in_tamil_alone(): void
    {
        $question = $this->question(['topic' => 'modern-tamil-poets', 'answer' => 'A']);
        unset($question['en']);
        $question['ta'] = [
            'q' => '‘தென்மொழி’ இதழின் வாயிலாகத் தமிழுணர்வைப் பரப்பியவர் யார்?',
            'options' => ['பெருஞ்சித்திரனார்', 'பாரதிதாசன்', 'தேவநேயப் பாவாணர்', 'திரு.வி.க.'],
            'explanation' => 'பாவலரேறு பெருஞ்சித்திரனார் தென்மொழி, தமிழ்ச்சிட்டு இதழ்களை நடத்தினார்.',
        ];
        $file = $this->file([$question]);

        $this->artisan('questions:import', ['file' => $file])->expectsOutputToContain('1 added to the review queue')->assertSuccessful();
        $this->artisan('questions:import', ['file' => $file])->expectsOutputToContain('1 already in the bank')->assertSuccessful();

        TenantContext::withoutTenancy(function (): void {
            $question = Question::query()->with('translations')->sole();
            $tamil = Language::byCode('ta')->getKey();

            $this->assertSame($tamil, $question->master_language_id);
            $this->assertSame('A', $question->correctOptionKey());

            $master = $question->translations->sole();
            $this->assertTrue($master->is_master);
            $this->assertSame($tamil, $master->language_id);
            $this->assertSame('பெருஞ்சித்திரனார்', $master->option_a);
        });
    }

    public function test_a_question_with_neither_language_is_refused(): void
    {
        $question = $this->question();
        unset($question['en'], $question['ta']);

        $this->artisan('questions:import', ['file' => $this->file([$question])])
            ->expectsOutputToContain('Question 1: the question needs an en or a ta version')
            ->assertFailed();
    }

    public function test_a_file_over_the_chapter_limit_is_refused_whole(): void
    {
        $questions = [];
        foreach (range(1, 3) as $n) {
            $questions[] = $this->question(['en' => ['q' => "Question number {$n}?", 'options' => ['w', 'x', 'y', 'z'], 'explanation' => 'e']]);
        }

        $this->artisan('questions:import', ['file' => $this->file($questions), '--max' => 2])
            ->expectsOutputToContain('The file has 3 questions; a chapter may have at most 2')
            ->assertFailed();

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Question::query()->count()));

        $this->artisan('questions:import', ['file' => $this->file($questions), '--max' => 0])
            ->expectsOutputToContain('3 added to the review queue')
            ->assertSuccessful();
    }

    public function test_loading_the_file_again_adds_nothing(): void
    {
        $file = $this->file([$this->question()]);

        $this->artisan('questions:import', ['file' => $file])->assertSuccessful();
        $this->artisan('questions:import', ['file' => $file])->expectsOutputToContain('0 added to the review queue, 1 already in the bank')->assertSuccessful();

        $this->assertSame(1, TenantContext::withoutTenancy(fn () => Question::query()->count()));
    }

    public function test_a_faulty_file_is_refused_whole_with_the_reasons(): void
    {
        $bad = $this->question(['answer' => 'E', 'page' => null]);
        $bad['ta']['options'][3] = '';

        $this->artisan('questions:import', ['file' => $this->file([$this->question(), $bad])])
            ->expectsOutputToContain('Question 2: the answer must be A, B, C or D')
            ->expectsOutputToContain('Question 2: the book page is missing')
            ->expectsOutputToContain('Question 2: the ta version needs four non-empty options')
            ->assertFailed();

        $this->assertSame(0, TenantContext::withoutTenancy(fn () => Question::query()->count()), 'Nothing half-loaded.');
    }

    public function test_an_approved_question_can_sit_on_a_paper(): void
    {
        $this->artisan('questions:import', ['file' => $this->file([$this->question()])])->assertSuccessful();

        // The citation the approval trigger requires is there.
        TenantContext::withoutTenancy(fn () => Question::query()->sole()->forceFill(['status' => 'APPROVED'])->save());

        $this->assertSame('APPROVED', TenantContext::withoutTenancy(fn () => Question::query()->sole()->status));
    }
}
