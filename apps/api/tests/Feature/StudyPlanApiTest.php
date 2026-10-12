<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\Exam;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The HTTP surface for study plans.
 *
 * The scheduler had unit coverage but no route, so it was unreachable from
 * outside PHP. These tests exercise it the way a client actually would.
 */
final class StudyPlanApiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Exam $exam;

    private Language $english;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PlanBlueprintSeeder::class);
        $this->seed(\Database\Seeders\TnpscGroup4Seeder::class);

        $this->english = Language::byCode('en');

        TenantContext::withoutTenancy(function (): void {
            $this->exam = Exam::query()->where('code', 'TNPSC_G4')->firstOrFail();

            $this->student = User::query()->create([
                'name' => 'Student', 'email' => 'plan@example.test', 'password' => 'secret-pass-1',
            ]);

            $org = Organization::query()->create([
                'name' => 'Student', 'slug' => 'plan-org', 'type' => 'individual',
                'owner_user_id' => $this->student->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $org->getKey(), 'user_id' => $this->student->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($org->getKey());
            $this->student->assignRole('student');
        });
    }

    public function test_each_of_the_five_modes_has_a_working_endpoint(): void
    {
        $modes = [
            'daily' => 1,
            'three-day' => 3,
            'weekly' => 7,
            'monthly' => 30,
        ];

        foreach ($modes as $path => $expectedDays) {
            $response = $this->actingAs($this->student, 'sanctum')
                ->postJson("/api/v1/study-plans/{$path}", [
                    'exam_id' => $this->exam->getKey(),
                    'language_id' => $this->english->getKey(),
                    'start_date' => now()->addDay()->toDateString(),
                    'hours_per_day' => 4,
                ]);

            $response->assertCreated();
            $this->assertSame(
                $expectedDays,
                $response->json('data.day_count'),
                "{$path} produced the wrong number of days",
            );
        }

        // The fifth mode runs to the exam date rather than a fixed horizon.
        $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/study-plans/model-test', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                'start_date' => now()->addDay()->toDateString(),
                'exam_date' => now()->addDays(21)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.mode', 'model_test');
    }

    public function test_a_plan_exposes_its_days_items_and_todays_view(): void
    {
        $planId = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/study-plans/weekly', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                // Starts today so the "today" view has a day to return.
                'start_date' => now()->toDateString(),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/study-plans/{$planId}")
            ->assertOk()
            ->assertJsonCount(7, 'data.days')
            ->assertJsonStructure([
                'data' => ['days' => [['day_number', 'date', 'role', 'items']]],
            ]);

        $today = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/study-plans/{$planId}/today")
            ->assertOk()
            ->json('data');

        $this->assertNotNull($today, 'a plan starting today must have a today view');
        $this->assertSame(1, $today['day_number']);
        $this->assertNotEmpty($today['items']);
    }

    public function test_completing_an_item_advances_the_days_progress(): void
    {
        $plan = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/study-plans/daily', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                'start_date' => now()->toDateString(),
            ])
            ->json('data');

        $itemId = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/study-plans/{$plan['id']}/today")
            ->json('data.items.0.id');

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/study-plans/{$plan['id']}/items/{$itemId}/complete")
            ->assertOk()
            ->assertJsonPath('data.item_id', $itemId);

        $this->assertGreaterThan(
            0,
            $this->actingAs($this->student, 'sanctum')
                ->getJson("/api/v1/study-plans/{$plan['id']}")
                ->json('data.days.0.completion_pct'),
        );
    }

    public function test_a_plan_belonging_to_another_student_is_not_visible(): void
    {
        $planId = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/study-plans/daily', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
            ])
            ->json('data.id');

        $stranger = TenantContext::withoutTenancy(function (): User {
            $user = User::query()->create([
                'name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'secret-pass-2',
            ]);

            $org = Organization::query()->create([
                'name' => 'Stranger', 'slug' => 'stranger-org', 'type' => 'individual',
                'owner_user_id' => $user->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $org->getKey(), 'user_id' => $user->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($org->getKey());
            $user->assignRole('student');

            return $user;
        });

        // 404 rather than 403: confirming the plan exists is itself a leak.
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/study-plans/{$planId}")
            ->assertNotFound();
    }

    public function test_at_least_one_study_day_a_week_is_required(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/study-plans/weekly', [
                'exam_id' => $this->exam->getKey(),
                'language_id' => $this->english->getKey(),
                'off_days' => [0, 1, 2, 3, 4, 5, 6],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.off_days.0', 'At least one day a week has to be a study day.');
    }
}
