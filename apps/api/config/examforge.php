<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ExamForge platform configuration
|--------------------------------------------------------------------------
|
| Rule from the architecture spec: operational limits that content or
| business teams tune (quotas, thresholds, difficulty mixes) live in the
| DATABASE, not here. This file holds only engineering defaults and the
| bootstrap values needed before the database is reachable.
|
*/

return [

    // The student's local day. The app clock stays UTC (stored timestamps
    // depend on it); only "which plan day is today" is read in this zone, so
    // a student in India does not see yesterday's plan until 05:30.
    'student_timezone' => env('EXAMFORGE_STUDENT_TIMEZONE', 'Asia/Kolkata'),

    'tenancy' => [
        // Content models where organization_id IS NULL means "platform-owned and
        // readable by every tenant". Private models must never appear here.
        'platform_shared_models' => [
            \App\Models\Exam::class,
            \App\Models\ExamVersion::class,
            \App\Models\Syllabus::class,
            \App\Models\SyllabusVersion::class,
            \App\Models\Source::class,
            \App\Models\Book::class,
        ],

        // Postgres session variables set per request/job. Read by RLS policies.
        'session_vars' => [
            'org' => 'app.org_id',
            'platform_admin' => 'app.is_platform_admin',
        ],
    ],

    'uploads' => [
        'max_bytes' => 512 * 1024 * 1024,          // 512 MB hard ceiling
        'max_pages' => 1200,
        'allowed_mime' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'presign_ttl_seconds' => 900,
        'download_ttl_seconds' => 300,
        'quarantine_prefix' => 'quarantine',
        'sources_prefix' => 'sources',
    ],

    'jobs' => [
        // Queue name per job type. Separate queues so a 20-minute OCR job can
        // never starve a 400ms PDF render.
        'queues' => [
            'critical' => 'critical',
            'ingest' => 'ingest',
            'ocr' => 'ocr',
            'structure' => 'structure',
            'embed' => 'embed',
            'generation' => 'generate',
            'validation' => 'validate',
            'translation' => 'translate',
            'pdf' => 'pdf',
            'plan' => 'plan',
            'prediction' => 'predict',
            'maintenance' => 'maintenance',
        ],
        'default_max_attempts' => 3,
        'backoff_seconds' => [60, 300, 900],
        'idempotency_ttl_hours' => 24,
    ],

    'documents' => [
        'code_prefix' => 'EF',
        'share_token_bytes' => 24,
    ],

    'prediction' => [
        'bands' => [
            'very_high' => 90,
            'high' => 75,
            'medium' => 60,
            'low' => 40,
            'very_low' => 0,
        ],
        // Required on every user-facing prediction surface. Never remove.
        'disclaimer_key' => 'prediction.disclaimer',
    ],

];
