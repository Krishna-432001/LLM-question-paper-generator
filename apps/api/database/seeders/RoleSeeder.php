<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RoleSeeder extends Seeder
{
    /** resource.action[.scope] — no role check ever appears inline in code. */
    private const PERMISSIONS = [
        'exams.view', 'exams.manage',
        'syllabi.view', 'syllabi.manage', 'syllabi.publish',
        'sources.view', 'sources.create', 'sources.process', 'sources.delete',
        'questions.view', 'questions.generate', 'questions.edit',
        'questions.approve.org', 'questions.approve.platform', 'questions.reject',
        'tests.view', 'tests.create.own', 'tests.create.org',
        'plans.view', 'plans.create.own', 'plans.create.org',
        'documents.download.own', 'documents.download.org', 'documents.share',
        'jobs.view', 'jobs.cancel', 'jobs.retry',
        'members.invite', 'members.manage',
        'billing.view', 'billing.manage',
        'prompts.manage', 'models.manage', 'usage.view', 'audit.view',
        'organizations.manage', 'users.impersonate',
    ];

    /** Roles are global definitions (organization_id NULL); grants are per-team. */
    private const ROLES = [
        'student' => [
            'exams.view', 'syllabi.view', 'questions.view', 'tests.view',
            'tests.create.own', 'plans.view', 'plans.create.own',
            'documents.download.own', 'documents.share', 'jobs.view', 'billing.view',
        ],
        'teacher' => [
            'exams.view', 'syllabi.view', 'sources.view', 'sources.create',
            'questions.view', 'questions.generate', 'tests.view', 'tests.create.org',
            'plans.view', 'plans.create.org', 'documents.download.org',
            'documents.share', 'jobs.view', 'jobs.cancel',
        ],
        'organization_admin' => [
            'exams.view', 'exams.manage', 'syllabi.view', 'syllabi.manage', 'sources.view', 'sources.create',
            'sources.process', 'sources.delete', 'questions.view', 'questions.generate',
            'questions.edit', 'questions.approve.org', 'questions.reject',
            'tests.view', 'tests.create.org', 'plans.view', 'plans.create.org',
            'documents.download.org', 'documents.share', 'jobs.view', 'jobs.cancel',
            'members.invite', 'members.manage', 'billing.view', 'billing.manage',
            'usage.view',
        ],
        'content_reviewer' => [
            'exams.view', 'syllabi.view', 'sources.view', 'questions.view',
            'questions.edit', 'questions.approve.platform', 'questions.reject',
            'jobs.view',
        ],
        'platform_admin' => self::PERMISSIONS,
        'super_admin' => self::PERMISSIONS,
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
