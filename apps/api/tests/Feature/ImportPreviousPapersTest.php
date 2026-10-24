<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\CanonicalTopic;
use App\Models\PreviousYearPaper;
use App\Models\PreviousYearQuestion;
use App\Models\TopicFrequency;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\TnpscGroup4Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** pyq:import-papers — classified previous-year papers in, prediction weights rebuilt. */
final class ImportPreviousPapersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(TnpscGroup4Seeder::class);
    }

    /** @param  list<array<string, mixed>>  $papers */
    private function file(array $papers): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ef-pyq-');
        file_put_contents($path, json_encode(['exam' => 'TNPSC_G4', 'papers' => $papers], JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** @return array<string, mixed> */
    private function paper(int $year, array $topics): array
    {
        $questions = [];

        foreach ($topics as $i => $topic) {
            $questions[] = [
                'number' => $i + 1,
                'text' => "Question {$i} of {$year}?",
                'options' => ['one', 'two', 'three', 'four'],
                'answer' => $year === 2011 ? null : 'B',
                'topic' => $topic,
                'type' => 'mcq4',
                'difficulty' => 'medium',
            ];
        }

        return ['year' => $year, 'paper_name' => "TNPSC Group 4 {$year}", 'notes' => 'From the analysis.', 'questions' => $questions];
    }

    public function test_papers_land_as_platform_content_and_rebuild_the_prediction_weights(): void
    {
        $file = $this->file([
            $this->paper(2011, ['Constitution of India', 'Modern Tamil Poets']),
            $this->paper(2025, ['constitution-of-india', 'Constitution of India', 'Indian National Movement']),
        ]);

        $this->artisan('pyq:import-papers', ['file' => $file])
            ->expectsOutputToContain('Topic frequencies rebuilt: 3 topics from 5 questions over 2 years.')
            ->assertSuccessful();

        TenantContext::withoutTenancy(function (): void {
            $this->assertSame(2, PreviousYearPaper::query()->whereNull('organization_id')->where('status', 'parsed')->count());
            $this->assertSame(5, PreviousYearQuestion::query()->where('mapping_source', 'analysis')->count());
            $this->assertSame(2, PreviousYearQuestion::query()->whereNull('correct_option')->count(), 'No key was published for 2011.');

            $constitution = CanonicalTopic::query()->where('slug', 'constitution-of-india')->value('id');
            $row = TopicFrequency::query()->where('canonical_topic_id', $constitution)->sole();
            $this->assertSame([3, 2, 2011, 2025], [$row->total_asked, $row->years_asked, $row->first_asked_year, $row->last_asked_year]);
            $this->assertEquals(1.0, (float) $row->frequency_score, 'The busiest topic, asked in the latest year.');
        });
    }

    public function test_loading_a_paper_again_replaces_its_questions(): void
    {
        $this->artisan('pyq:import-papers', ['file' => $this->file([$this->paper(2025, ['Constitution of India', 'Modern Tamil Poets'])])])->assertSuccessful();
        $this->artisan('pyq:import-papers', ['file' => $this->file([$this->paper(2025, ['Modern Tamil Poets'])])])->assertSuccessful();

        TenantContext::withoutTenancy(function (): void {
            $this->assertSame(1, PreviousYearPaper::query()->count());
            $this->assertSame(1, PreviousYearQuestion::query()->count());
        });
    }

    public function test_a_file_with_an_unknown_topic_is_refused_whole(): void
    {
        $this->artisan('pyq:import-papers', ['file' => $this->file([
            $this->paper(2024, ['Constitution of India']),
            $this->paper(2025, ['Astrology']),
        ])])
            ->expectsOutputToContain('TNPSC Group 4 2025: question 1: unknown topic "Astrology"')
            ->assertFailed();

        TenantContext::withoutTenancy(fn () => $this->assertSame(0, PreviousYearPaper::query()->count()));
    }
}
