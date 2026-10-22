<?php

declare(strict_types=1);

namespace App\Domain\Questions\Aptitude;

use App\Domain\Questions\Aptitude\Books\Std10Mensuration;
use App\Domain\Questions\Aptitude\Books\Std6HcfLcm;
use App\Domain\Questions\Aptitude\Books\Std6PerimeterArea;
use App\Domain\Questions\Aptitude\Books\Std6Term1;
use App\Domain\Questions\Aptitude\Books\Std6Term2;
use App\Domain\Questions\Aptitude\Books\Std7Circles;
use App\Domain\Questions\Aptitude\Books\Std7Term1;
use App\Domain\Questions\Aptitude\Books\Std7Term2;
use App\Domain\Questions\Aptitude\Books\Std7Term3;
use App\Domain\Questions\Aptitude\Books\Std8InformationProcessing;
use App\Domain\Questions\Aptitude\Books\Std8MeasureAlgebra;
use App\Domain\Questions\Aptitude\Books\Std8Numbers;
use App\Domain\Questions\Aptitude\Books\Std9Mensuration;

/** Every textbook's aptitude set, so the generator, the importer and review all see the same list. */
final class AptitudeBooks
{
    /**
     * @return list<AptitudeBook>
     */
    public static function all(): array
    {
        return [
            new Std6Term1,
            new Std6HcfLcm,
            new Std6PerimeterArea,
            new Std7Term1,
            new Std7Circles,
            new Std7Term3,
            new Std8Numbers,
            new Std8MeasureAlgebra,
            new Std9Mensuration,
            new Std10Mensuration,
            new Std6Term2,
            new Std7Term2,
            new Std8InformationProcessing,
        ];
    }

    /**
     * The breadcrumbs of all generated aptitude chapters, the original Std 8 set included.
     *
     * @return list<string>
     */
    public static function breadcrumbs(): array
    {
        return [
            AptitudeGenerator::BREADCRUMB,
            ...array_map(static fn (AptitudeBook $book): string => $book->breadcrumb(), self::all()),
        ];
    }

    /** Whether a chapter is a generated aptitude set, which is exempt from the 50-a-chapter limit. */
    public static function isGenerated(?string $breadcrumb): bool
    {
        return $breadcrumb !== null && in_array($breadcrumb, self::breadcrumbs(), true);
    }
}
