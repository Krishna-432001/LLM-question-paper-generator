<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeders write platform-owned rows (organization_id IS NULL), which
        // no tenant context is allowed to create. This is the one legitimate
        // place to bypass RLS.
        TenantContext::withoutTenancy(function (): void {
            $this->call([
                LanguageSeeder::class,
                RoleSeeder::class,
                SubscriptionPlanSeeder::class,
                AiModelSeeder::class,
                PromptSeeder::class,
                PlanBlueprintSeeder::class,
                TnpscGroup4Seeder::class,
                // After the syllabus exists: it rewrites unit weights and
                // orders the content backlog against them.
                TnpscBlueprintSeeder::class,
                // Translation engine: starter en → ta terminology and proper
                // nouns. Only adds missing entries, so reviewer edits survive.
                GlossarySeeder::class,
            ]);
        });
    }
}
