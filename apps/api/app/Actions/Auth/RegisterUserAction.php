<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Tenancy\TenantContext;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registration provisions a personal organization for every individual signup.
 *
 * This is what lets the rest of the codebase assume "there is always a tenant"
 * — no individual-vs-institute branching anywhere downstream.
 */
final class RegisterUserAction
{
    public function execute(
        string $name,
        string $email,
        string $password,
        ?string $languageCode = 'en',
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $languageCode): User {
            return TenantContext::withoutTenancy(function () use ($name, $email, $password, $languageCode): User {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'preferred_language_id' => Language::byCode($languageCode ?? 'en')?->getKey(),
                ]);

                $organization = Organization::query()->create([
                    'name' => "{$name}'s workspace",
                    'slug' => $this->uniqueSlug($name),
                    'type' => 'individual',
                    'owner_user_id' => $user->getKey(),
                    'seat_limit' => 1,
                    'trial_ends_at' => now()->addDays(14),
                ]);

                OrganizationUser::query()->create([
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                    'status' => 'active',
                    'is_default' => true,
                ]);

                // In teams mode the active team must be set on the registrar
                // before assigning, or the grant lands on the wrong tenant.
                app(PermissionRegistrar::class)->setPermissionsTeamId($organization->getKey());
                $user->assignRole('student');

                $this->startFreePlan($organization);

                return $user->fresh();
            });
        });
    }

    private function startFreePlan(Organization $organization): void
    {
        $plan = SubscriptionPlan::query()->where('code', 'free')->first();

        if ($plan === null) {
            return;
        }

        Subscription::query()->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => 'active',
            'provider' => 'internal',
            'seats' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addYears(10),
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Organization::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
