<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Question;
use App\Models\User;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesTestData;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The overview is the first thing an operator sees, so a wrong number here is
 * a wrong decision about what to work on next. It is also the page most likely
 * to be loaded repeatedly, which is why the query count is pinned too.
 */
final class DashboardTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        // One call for user, organisation, membership and role — the four
        // things every authenticated test needs and any one of which returns
        // an unhelpful 403 when missed.
        $this->admin = $this->makeAdmin('dash-admin@example.test');
    }

    public function test_it_separates_the_bank_into_states_that_mean_different_things(): void
    {
        $this->makeQuestions(7, 'APPROVED');
        $this->makeQuestions(3, 'PENDING_REVIEW');
        $this->makeQuestions(2, 'AI_VALIDATED');
        $this->makeQuestions(11, 'REJECTED');

        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data.questions');

        $this->assertSame(23, $data['total']);
        $this->assertSame(7, $data['approved']);
        // Both pre-review states are one number to an operator: work waiting.
        $this->assertSame(5, $data['pending_review']);
        $this->assertSame(11, $data['rejected']);
    }

    public function test_it_reports_why_questions_were_discarded(): void
    {
        $this->makeQuestions(1, 'REJECTED');

        TenantContext::withoutTenancy(function (): void {
            $question = Question::query()->firstOrFail();

            foreach (['DUPLICATE_SEMANTIC', 'DUPLICATE_SEMANTIC', 'ANSWER_MISMATCH'] as $code) {
                DB::table('question_rejections')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid7(),
                    'question_id' => $question->getKey(),
                    'stage' => 'V6',
                    'code' => $code,
                    'created_at' => now(),
                ]);
            }
        });

        $reasons = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data.questions.top_rejection_reasons');

        // Ordered by frequency: the top reason is the one worth acting on.
        $this->assertSame(2, $reasons['DUPLICATE_SEMANTIC']);
        $this->assertSame(1, $reasons['ANSWER_MISMATCH']);
        $this->assertSame('DUPLICATE_SEMANTIC', array_key_first($reasons));
    }

    public function test_an_empty_system_reports_zeroes_rather_than_failing(): void
    {
        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        // A fresh install must not show an error page as its first screen.
        $this->assertSame(0, $data['questions']['total']);
        $this->assertSame(0, $data['books']['total']);
        $this->assertSame(0, $data['pyqs']['papers']);
        $this->assertNull($data['pyqs']['years']);
        $this->assertEquals(0, $data['ai']['spend_inr']);
    }

    public function test_it_loads_in_a_bounded_number_of_queries(): void
    {
        $this->makeQuestions(20, 'APPROVED');

        // Measured against a trivial authenticated request, so the budget is
        // the dashboard's own cost rather than the fixed price of auth and
        // tenant resolution, which would otherwise dominate the number and
        // make the assertion meaningless.
        $baseline = $this->countQueries(fn () => $this->actingAs($this->admin)
            ->getJson('/api/v1/exams')->assertOk());

        $total = $this->countQueries(fn () => $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard')->assertOk());

        $own = $total - $baseline;

        // A card-per-query dashboard becomes the slowest page in the app and
        // is then loaded on every navigation. Five sections, aggregated.
        $this->assertLessThanOrEqual(
            12,
            $own,
            "The dashboard issued {$own} queries of its own; it must aggregate rather than count per card.",
        );
    }

    private function countQueries(callable $callback): int
    {
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $callback();

        // Listeners accumulate across calls within one test, so the count is
        // reset by re-registering rather than by trusting a single listener.
        DB::flushQueryLog();

        return $queries;
    }

    public function test_it_states_which_model_is_actually_generating(): void
    {
        $ai = $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data.ai');

        // Whether the questions came from a real model or the offline stand-in
        // changes how much anyone should trust them.
        $this->assertSame('fake', $ai['driver']);
    }
}
