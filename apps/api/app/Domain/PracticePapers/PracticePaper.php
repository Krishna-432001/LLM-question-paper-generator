<?php

declare(strict_types=1);

namespace App\Domain\PracticePapers;

use App\Models\Test;
use App\Models\User;

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
}
