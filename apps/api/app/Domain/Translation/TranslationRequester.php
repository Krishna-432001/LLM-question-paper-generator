<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Actions\Jobs\DispatchTrackedJobAction;
use App\Domain\Jobs\JobStatus;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\Translation\TranslateQuestions;
use App\Models\AiJob;
use App\Models\Exam;
use App\Models\Language;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lazy translation: the bank is translated when a paper in that language is
 * asked for, not ahead of time.
 *
 * A paper requested in Tamil whose exam has too few Tamil questions calls
 * queueMissing(). It orders translation of the shortfall and returns the job,
 * so the caller can tell the user more questions are on their way. Calling it
 * again while that job is still queued or running returns the same job: ten
 * students opening the same Tamil test do not order ten translations.
 */
final class TranslationRequester
{
    public function __construct(
        private readonly TranslationStore $store,
        private readonly TranslationPrompts $prompts,
        private readonly DispatchTrackedJobAction $dispatch,
    ) {}

    /**
     * @return AiJob|null  the queued (or already running) job; null when there is nothing to translate
     */
    public function queueMissing(string $examId, string $languageId, int $count, ?string $userId): ?AiJob
    {
        $language = Language::query()->find($languageId);

        if ($language === null || $count < 1) {
            return null;
        }

        // A language with no prompt is a configuration gap, not a reason to
        // fail the paper being built. Logged so it is visible, then skipped.
        if (! $this->prompts->exists($language)) {
            Log::warning('translation.no_prompt', ['language' => $language->code]);

            return null;
        }

        return DB::transaction(function () use ($examId, $language, $count, $userId): ?AiJob {
            // Serialises concurrent callers for the same exam and language,
            // so two requests a millisecond apart cannot both dispatch.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["translate-request:{$examId}:{$language->getKey()}"]);

            $existing = $this->unfinishedJob($examId, $language->getKey());

            if ($existing !== null) {
                return $existing;
            }

            $missing = $this->store->needingTranslationForExam($examId, $language->getKey())->count();

            if ($missing === 0) {
                return null;
            }

            $organizationId = app()->bound(TenantContext::class)
                ? app(TenantContext::class)->organizationId
                : null;

            return $this->dispatch->execute(
                type: 'translation',
                jobClass: TranslateQuestions::class,
                input: [
                    'language_id' => $language->getKey(),
                    'exam_id' => $examId,
                    'limit' => min($count, $missing, TranslateQuestions::MAX_LIMIT),
                    'trigger' => 'on_demand',
                ],
                organizationId: $organizationId,
                userId: $userId,
                subjectType: Exam::class,
                subjectId: $examId,
            );
        });
    }

    /**
     * Any tenant's job counts: translations are shared bank content, so a
     * job someone else already queued will serve this request too.
     */
    public function unfinishedJob(string $examId, string $languageId): ?AiJob
    {
        $find = fn (): ?AiJob => AiJob::query()
            ->withoutGlobalScope('organization')
            ->where('type', 'translation')
            ->whereIn('status', [
                JobStatus::Queued->value,
                JobStatus::Processing->value,
                JobStatus::Validating->value,
                JobStatus::RetryScheduled->value,
            ])
            ->where('input->exam_id', $examId)
            ->where('input->language_id', $languageId)
            ->latest('queued_at')
            ->first();

        // withoutTenancy() switches the bypass OFF when it returns, whatever
        // it was before. Called from code that is already bypassing (a
        // seeder, a console command), wrapping again would silently re-arm
        // row-level security for the caller's next write.
        $bypassing = DB::selectOne("SELECT coalesce(current_setting('app.bypass_rls', true), 'off') AS v")?->v === 'on';

        return $bypassing ? $find() : TenantContext::withoutTenancy($find);
    }
}
