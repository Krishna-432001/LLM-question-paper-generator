<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers;

use App\Models\Test;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What marks a test as an uploaded practice paper, and who may touch one.
 *
 * A practice paper is an ordinary custom test so the existing runner, results
 * and solutions all work on it unchanged. What sets it apart lives in its
 * selection_profile — the column that already records how a test was put
 * together — as kind "practice_paper", together with the source it was read
 * from and the number each question carried in the printed paper. That last
 * part is what an answer key typed as "1-A 2-C" is matched against: question
 * 7 in the paper is not sequence 7 once question 4 was unreadable.
 */
final class PracticePaper
{
    public const KIND = 'practice_paper';

    public static function is(Test $test): bool
    {
        return $test->type === 'custom'
            && ($test->selection_profile['kind'] ?? null) === self::KIND;
    }

    /**
     * Only the student who uploaded it, and platform admins. Not the rest of
     * the organization: a paper someone uploaded to practise on is theirs,
     * and its answers are theirs to fill in.
     */
    public static function canManage(Test $test, User $user): bool
    {
        return self::is($test)
            && ($test->created_by === $user->getKey() || $user->isPlatformAdmin());
    }

    /**
     * Practice-paper questions are the uploader's, answers included. Every
     * other question in the bank stays as visible as it was.
     */
    public static function hidePrivateQuestions(Builder $query, ?User $user): Builder
    {
        if ($user?->isPlatformAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('questions.origin', '!=', 'previous_paper')
            ->orWhere('questions.created_by', $user?->getKey()));
    }

    /**
     * A previous-paper upload is visible to the person who uploaded it. Staff
     * still see the papers the institute uploaded for PYQ analysis — just not
     * one a student uploaded to practise on.
     */
    public static function hidePrivateSources(Builder $query, ?User $user): Builder
    {
        if ($user?->isPlatformAdmin()) {
            return $query;
        }

        $staff = $user?->can('sources.view') ?? false;

        return $query->where(function (Builder $q) use ($user, $staff): void {
            $q->where('sources.kind', '!=', 'previous_paper')
                ->orWhere('sources.uploaded_by', $user?->getKey());

            if ($staff) {
                $q->orWhereNotExists(fn ($tests) => $tests
                    ->selectRaw('1')
                    ->from('tests')
                    ->where('tests.type', 'custom')
                    ->whereRaw("tests.selection_profile->>'kind' = ?", [self::KIND])
                    ->whereRaw("tests.selection_profile->>'source_id' = sources.id::text"));
            }
        });
    }

    /**
     * @return array<int, int>  sequence => the question's number in the printed paper
     */
    public static function questionNumbers(Test $test): array
    {
        $numbers = $test->selection_profile['question_numbers'] ?? [];
        $bySequence = [];

        foreach (array_values($numbers) as $i => $number) {
            $bySequence[$i + 1] = (int) $number;
        }

        return $bySequence;
    }

    /**
     * @return array<int, int>  sequence => which part of the paper the question is in (1 when it has one)
     */
    public static function questionSections(Test $test): array
    {
        $sections = $test->selection_profile['question_sections'] ?? [];
        $bySequence = [];

        foreach (array_keys(self::questionNumbers($test)) as $sequence) {
            $bySequence[$sequence] = (int) ($sections[$sequence - 1] ?? 1);
        }

        return $bySequence;
    }
}
