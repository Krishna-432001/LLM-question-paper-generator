<?php

declare(strict_types=1);

namespace App\Domain\Translation\Exceptions;

use RuntimeException;

/**
 * A finished translation that must not be stored.
 *
 *   master_changed   — the master question was edited while the model was
 *                      working, so the text translated is no longer the text
 *                      a student would see. Storing it would serve the old
 *                      question (and its old numbers) under the new one.
 *   repeats_rejected — the model produced, word for word, a translation a
 *                      reviewer already rejected for this question.
 */
final class TranslationDiscarded extends RuntimeException
{
    public const MASTER_CHANGED = 'master_changed';

    public const REPEATS_REJECTED = 'repeats_rejected';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
