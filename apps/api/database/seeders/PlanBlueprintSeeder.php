<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlanBlueprint;
use Illuminate\Database\Seeder;

/**
 * The five plan modes from the specification, as data.
 *
 * horizon_days = 0 means "run to the exam date" rather than a fixed window.
 */
final class PlanBlueprintSeeder extends Seeder
{
    private const BLUEPRINTS = [
        [
            'mode' => 'daily', 'name' => 'Daily Plan', 'horizon_days' => 1,
            'structure' => ['roles' => ['new']],
            'test_config' => ['daily_questions' => 20, 'daily_minutes' => 15],
            'documents' => ['study_plan', 'question_paper', 'answer_key'],
        ],
        [
            // D1 new · D2 new + revision · D3 revision + test
            'mode' => 'three_day', 'name' => '3-Day Cycle', 'horizon_days' => 3,
            'structure' => ['roles' => ['new', 'new_plus_revision', 'test']],
            'test_config' => [
                'daily_questions' => 15, 'daily_minutes' => 12,
                'cycle_days' => 3, 'cycle_questions' => 50,
                'cycle_minutes' => 45, 'cycle_test_name' => '3-Day test',
            ],
            'documents' => ['study_plan', 'question_paper', 'answer_key'],
        ],
        [
            'mode' => 'weekly', 'name' => 'Weekly Plan', 'horizon_days' => 7,
            'structure' => ['roles' => ['new', 'new', 'new', 'new', 'new', 'new_plus_revision', 'test']],
            'test_config' => [
                'daily_questions' => 15, 'daily_minutes' => 12,
                'cycle_days' => 7, 'cycle_questions' => 75,
                'cycle_minutes' => 60, 'cycle_test_name' => 'Weekly test',
            ],
            'documents' => ['study_plan', 'question_paper', 'answer_key'],
        ],
        [
            'mode' => 'monthly', 'name' => 'Monthly Plan', 'horizon_days' => 30,
            'structure' => ['weeks' => 4, 'revision_week' => 5],
            'test_config' => [
                'daily_questions' => 15, 'daily_minutes' => 12,
                'cycle_days' => 7, 'cycle_questions' => 75,
                'cycle_minutes' => 60, 'cycle_test_name' => 'Weekly test',
                'final_questions' => 100,
            ],
            'documents' => ['study_plan', 'question_paper', 'answer_key'],
        ],
        [
            // Point-in-time full-pattern simulation; runs to the exam date.
            'mode' => 'model_test', 'name' => 'Model Test Plan', 'horizon_days' => 0,
            'structure' => ['simulation' => true],
            'test_config' => ['cycle_days' => 7, 'cycle_questions' => 100, 'cycle_minutes' => 180,
                'cycle_test_name' => 'Model examination'],
            'documents' => ['question_paper', 'answer_key', 'explanations'],
        ],
    ];

    public function run(): void
    {
        foreach (self::BLUEPRINTS as $blueprint) {
            PlanBlueprint::query()->updateOrCreate(
                ['mode' => $blueprint['mode'], 'is_default' => true],
                [
                    'name' => $blueprint['name'],
                    'horizon_days' => $blueprint['horizon_days'],
                    'structure' => $blueprint['structure'],
                    'test_config' => $blueprint['test_config'],
                    'documents' => $blueprint['documents'],
                ],
            );
        }
    }
}
