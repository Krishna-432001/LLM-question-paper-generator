<?php

declare(strict_types=1);

namespace App\Domain\Tests\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The bank cannot fill the paper as asked — as opposed to the request itself
 * being invalid. Kept distinct so the caller can do something about it (start
 * translating the missing questions) instead of only refusing.
 */
final class NotEnoughQuestions extends UnprocessableEntityHttpException {}
