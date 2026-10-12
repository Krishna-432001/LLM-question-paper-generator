<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlanQuota;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

final class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Every number here is a database row, never a code constant, so pricing
     * experiments are a DB update rather than a deploy.
     * -1 means unlimited.
     */
    private const PLANS = [
        [
            'code' => 'free', 'name' => 'Free', 'monthly' => 0, 'yearly' => 0, 'order' => 1,
            'features' => ['prediction' => false, 'institute_dashboard' => false, 'branding' => false, 'api' => false],
            'quotas' => [
                'questions_practiced' => ['day', 30], 'pdf_generated' => ['month', 5],
                'model_tests' => ['month', 1], 'exams' => ['lifetime', 1],
                'languages' => ['lifetime', 1], 'seats' => ['lifetime', 1],
                'questions_generated' => ['month', 0], 'storage_bytes' => ['lifetime', 0],
            ],
        ],
        [
            'code' => 'basic', 'name' => 'Basic', 'monthly' => 99, 'yearly' => 999, 'order' => 2,
            'features' => ['prediction' => false, 'institute_dashboard' => false, 'branding' => false, 'api' => false],
            'quotas' => [
                'questions_practiced' => ['day', 200], 'pdf_generated' => ['month', 50],
                'model_tests' => ['month', 4], 'exams' => ['lifetime', 2],
                'languages' => ['lifetime', 2], 'seats' => ['lifetime', 1],
                'questions_generated' => ['month', 0], 'storage_bytes' => ['lifetime', 0],
            ],
        ],
        [
            'code' => 'pro', 'name' => 'Pro', 'monthly' => 299, 'yearly' => 2999, 'order' => 3,
            'features' => ['prediction' => true, 'institute_dashboard' => false, 'branding' => false, 'api' => false],
            'quotas' => [
                'questions_practiced' => ['day', -1], 'pdf_generated' => ['month', 300],
                'model_tests' => ['month', 15], 'exams' => ['lifetime', 5],
                'languages' => ['lifetime', -1], 'seats' => ['lifetime', 1],
                'questions_generated' => ['month', 200], 'storage_bytes' => ['lifetime', 3221225472],
            ],
        ],
        [
            'code' => 'premium', 'name' => 'Premium', 'monthly' => 599, 'yearly' => 5999, 'order' => 4,
            'features' => ['prediction' => true, 'institute_dashboard' => false, 'branding' => false, 'api' => false],
            'quotas' => [
                'questions_practiced' => ['day', -1], 'pdf_generated' => ['month', 2000],
                'model_tests' => ['month', -1], 'exams' => ['lifetime', -1],
                'languages' => ['lifetime', -1], 'seats' => ['lifetime', 1],
                'questions_generated' => ['month', 1000], 'storage_bytes' => ['lifetime', 21474836480],
            ],
        ],
        [
            'code' => 'institute', 'name' => 'Institute', 'monthly' => 4999, 'yearly' => 49999, 'order' => 5,
            'features' => ['prediction' => true, 'institute_dashboard' => true, 'branding' => true, 'api' => true],
            'quotas' => [
                'questions_practiced' => ['day', -1], 'pdf_generated' => ['month', 20000],
                'model_tests' => ['month', -1], 'exams' => ['lifetime', -1],
                'languages' => ['lifetime', -1], 'seats' => ['lifetime', 50],
                'questions_generated' => ['month', 10000], 'storage_bytes' => ['lifetime', 536870912000],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $definition) {
            $plan = SubscriptionPlan::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'price_inr_monthly' => $definition['monthly'],
                    'price_inr_yearly' => $definition['yearly'],
                    'features' => $definition['features'],
                    'is_public' => true,
                    'sort_order' => $definition['order'],
                ],
            );

            foreach ($definition['quotas'] as $metric => [$period, $limit]) {
                PlanQuota::query()->updateOrCreate(
                    ['plan_id' => $plan->getKey(), 'metric' => $metric, 'period' => $period],
                    ['limit_value' => $limit],
                );
            }
        }
    }
}
