<?php

declare(strict_types=1);

namespace Tests\Tenancy;

use App\Domain\Tenancy\TenantContext;
use App\Models\AiJob;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The suite that must never go red.
 *
 * It proves all three isolation layers independently: the Eloquent global
 * scope, the PostgreSQL RLS policy (via raw SQL that bypasses Eloquent
 * entirely), and the HTTP surface.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        [$this->orgA, $this->userA] = $this->makeTenant('alpha');
        [$this->orgB] = $this->makeTenant('beta');
    }

    public function test_eloquent_scope_hides_another_tenants_private_rows(): void
    {
        $foreignJob = TenantContext::withoutTenancy(fn () => AiJob::query()->create([
            'organization_id' => $this->orgB->getKey(),
            'type' => 'ingest',
            'queue' => 'ingest',
        ]));

        $this->actingAsTenant($this->userA, $this->orgA);

        $this->assertNull(
            AiJob::query()->find($foreignJob->getKey()),
            'AiJob leaked across tenants through the Eloquent scope.'
        );
    }

    public function test_row_level_security_blocks_raw_sql(): void
    {
        $foreignSource = TenantContext::withoutTenancy(fn () => $this->makeSource($this->orgB));

        $this->actingAsTenant($this->userA, $this->orgA);

        // Deliberately bypasses Eloquent. If layer 2 were the only defence,
        // this query would return the row.
        $rows = DB::select('SELECT id FROM sources WHERE id = ?', [$foreignSource->getKey()]);

        $this->assertSame([], $rows, 'RLS did not block a raw cross-tenant read.');
    }

    public function test_platform_content_is_readable_by_every_tenant(): void
    {
        $platformSource = TenantContext::withoutTenancy(fn () => $this->makeSource(null));

        $this->actingAsTenant($this->userA, $this->orgA);

        $this->assertNotNull(
            Source::query()->find($platformSource->getKey()),
            'Platform-owned content should be inherited by every tenant.'
        );
    }

    public function test_api_returns_404_for_another_tenants_source(): void
    {
        $foreignSource = TenantContext::withoutTenancy(fn () => $this->makeSource($this->orgB));

        $this->actingAs($this->userA, 'sanctum')
            ->getJson("/api/v1/sources/{$foreignSource->getKey()}")
            // 404 rather than 403: telling the caller the row exists is
            // itself a cross-tenant leak.
            ->assertNotFound();
    }

    public function test_forged_organization_header_is_ignored(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->withHeader('X-Organization-Id', $this->orgB->getKey())
            ->getJson('/api/v1/me')
            ->assertForbidden();
    }

    /** @return array{0: Organization, 1: User} */
    private function makeTenant(string $slug): array
    {
        return TenantContext::withoutTenancy(function () use ($slug): array {
            $user = User::query()->create([
                'name' => ucfirst($slug),
                'email' => "{$slug}@example.test",
                'password' => 'password-'.$slug,
            ]);

            $organization = Organization::query()->create([
                'name' => ucfirst($slug),
                'slug' => $slug,
                'type' => 'individual',
                'owner_user_id' => $user->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'status' => 'active',
                'is_default' => true,
            ]);

            return [$organization, $user];
        });
    }

    private function makeSource(?Organization $organization): Source
    {
        return Source::query()->create([
            'organization_id' => $organization?->getKey(),
            'kind' => 'textbook',
            'title' => 'Fixture book',
            'language_id' => Language::byCode('en')->getKey(),
            'rights_basis' => 'govt_open',
            'storage_path' => 'quarantine/ab/fixture.pdf',
            'original_filename' => 'fixture.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'content_sha256' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function actingAsTenant(User $user, Organization $organization): void
    {
        $context = TenantContext::forUser($user, $organization);
        app()->instance(TenantContext::class, $context);
        $context->applyToDatabase();
    }
}
