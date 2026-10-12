<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trends decide what someone works on next, so the shape has to be honest.
 *
 * The failure guarded against here is subtle and easy to ship: grouping by
 * date returns only the days something happened, so a chart drawn from it
 * omits the quiet days entirely and reads as steady output when the truth is a
 * two-week stall.
 */
final class TrendTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->admin = User::query()->create([
                'name' => 'Admin', 'email' => 'trend-admin@example.test',
                'password' => 'secret-pass', 'is_platform_admin' => true,
            ]);

            $org = Organization::query()->create([
                'name' => 'Trend Org', 'slug' => 'trend-org',
                'type' => 'individual', 'owner_user_id' => $this->admin->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $org->getKey(),
                'user_id' => $this->admin->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);
        });
    }

    private function draftedOn(string $date, int $count): void
    {
        TenantContext::withoutTenancy(function () use ($date, $count): void {
            // A database trigger refuses to approve a question with no
            // citation, so the fixture has to satisfy the same rule real
            // questions do.
            $source = \App\Models\Source::query()->create([
                'organization_id' => null, 'kind' => 'textbook',
                'title' => 'Trend Source '.$date,
                'language_id' => $this->english->getKey(),
                'rights_basis' => 'govt_open', 'status' => 'ready',
                'storage_disk' => 'local', 'storage_path' => 'sources/trend-'.md5($date).'.pdf',
                'original_filename' => 'trend.pdf', 'file_size_bytes' => 1024,
                'content_sha256' => hash('sha256', $date), 'mime_type' => 'application/pdf',
            ]);

            for ($i = 0; $i < $count; $i++) {
                $question = Question::query()->create([
                    'organization_id' => null,
                    'master_language_id' => $this->english->getKey(),
                    'difficulty' => 'medium',
                    'status' => 'PENDING_REVIEW',
                    'origin' => 'ai_generated',
                ]);

                \App\Models\QuestionSource::query()->create([
                    'question_id' => $question->getKey(),
                    'source_id' => $source->getKey(),
                    'is_primary' => true,
                    'supporting_quote' => 'A quoted line.',
                ]);

                // Backdated directly: created_at is what the series buckets on.
                DB::table('questions')
                    ->where('id', $question->getKey())
                    ->update(['created_at' => $date]);
            }
        });
    }

    /** @return array<string, mixed> */
    private function trend(int $days = 30): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/v1/dashboard/trend?days={$days}")
            ->assertOk()
            ->json('data');
    }

    public function test_quiet_days_appear_as_zero_rather_than_being_omitted(): void
    {
        $this->draftedOn(now()->subDays(5)->toDateString().' 10:00:00', 4);

        $series = $this->trend(14)['series']['drafted'];

        // Fourteen requested, fourteen returned — one busy day and thirteen
        // quiet ones, not a single point that would draw as a flat line.
        $this->assertCount(14, $series);
        $this->assertEquals(4, collect($series)->sum('value'));

        $nonZero = collect($series)->where('value', '>', 0);
        $this->assertCount(1, $nonZero);
    }

    public function test_every_day_in_the_window_is_present_and_in_order(): void
    {
        $series = $this->trend(30)['series']['drafted'];

        $dates = array_column($series, 'date');

        $this->assertCount(30, $dates);
        $this->assertSame($dates, array_values(array_unique($dates)), 'No day may repeat.');

        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'Days must come back in order.');

        // Compared against the endpoint's own reported boundary, which is read
        // off the same calendar the database generated.
        $this->assertSame($this->trend(30)['to'], end($dates));
    }

    public function test_work_older_than_the_window_is_excluded(): void
    {
        $this->draftedOn(now()->subDays(40)->toDateString().' 10:00:00', 9);
        $this->draftedOn(now()->subDays(2)->toDateString().' 10:00:00', 3);

        $series = $this->trend(14)['series']['drafted'];

        // Only the recent three. Letting old work leak in would make a stalled
        // fortnight look productive.
        $this->assertEquals(3, collect($series)->sum('value'));
    }

    public function test_drafting_and_approving_are_counted_on_their_own_days(): void
    {
        $this->draftedOn(now()->subDays(6)->toDateString().' 10:00:00', 5);

        TenantContext::withoutTenancy(function (): void {
            DB::table('questions')->update([
                'status' => 'APPROVED',
                'approved_at' => now()->subDay(),
            ]);
        });

        $data = $this->trend(14)['series'];

        // Approval happens later than drafting. Conflating them would hide how
        // fast review actually moves.
        $draftedDay = collect($data['drafted'])->firstWhere('value', '>', 0);
        $approvedDay = collect($data['approved'])->firstWhere('value', '>', 0);

        $this->assertNotSame(
            $draftedDay['date'],
            $approvedDay['date'],
            'Drafting and approval must land on their own days.',
        );
        $this->assertGreaterThan($draftedDay['date'], $approvedDay['date']);
    }

    public function test_the_window_is_bounded(): void
    {
        // An unbounded range is an invitation to scan the whole table from a
        // query string.
        $this->assertSame(90, $this->trend(9999)['days']);
        $this->assertSame(7, $this->trend(1)['days']);
    }

    public function test_an_empty_system_still_returns_a_full_window(): void
    {
        $data = $this->trend(30);

        foreach ($data['series'] as $name => $series) {
            $this->assertCount(30, $series, "The {$name} series must still span the window.");
            $this->assertEquals(0, collect($series)->sum('value'));
        }
    }
}
