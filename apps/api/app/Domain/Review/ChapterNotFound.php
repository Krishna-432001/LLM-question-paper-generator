<?php

declare(strict_types=1);

namespace App\Domain\Review;

use RuntimeException;

/** The chapter key matches no chapter that still has questions waiting. */
final class ChapterNotFound extends RuntimeException {}
