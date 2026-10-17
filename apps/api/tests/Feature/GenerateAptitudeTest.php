<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Language;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/** questions:aptitude writes a file questions:import loads, with its Tamil and page. */
final class GenerateAptitudeTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    public function test_the_generated_file_loads_into_the_review_queue(): void
    {
        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);
        $source = $this->makeSource('Std 8 Mathematics Term-Full (English)');

        $file = tempnam(sys_get_temp_dir(), 'ef-aptitude-');
        $this->artisan('questions:aptitude', ['--per-template' => 2, '--out' => $file])->assertSuccessful();

        // The fixture source carries a numbered title; point the file at it.
        $data = json_decode((string) file_get_contents($file), true);
        $data['source'] = $source->title;
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
        $count = count($data['questions']);

        $this->artisan('questions:import', ['file' => $file])
            ->expectsOutputToContain("{$count} added to the review queue")
            ->assertSuccessful();

        TenantContext::withoutTenancy(function () use ($count, $source): void {
            $this->assertSame($count, Question::query()->count());

            $question = Question::query()->with(['translations', 'sources'])->first();
            $this->assertSame(Language::byCode('en')->getKey(), $question->master_language_id);
            $this->assertTrue($question->translations->contains('language_id', Language::byCode('ta')->getKey()));
            $this->assertSame($source->getKey(), $question->sources->sole()->source_id);
            $this->assertContains(
                DB::table('canonical_topics')->where('id', $question->canonical_topic_id)->value('slug'),
                ['simplification-and-percentage', 'ratio-proportion-and-averages', 'time-work-and-distance'],
            );
        });
    }
}
