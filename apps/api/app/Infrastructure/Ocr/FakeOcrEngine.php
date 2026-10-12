<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Ingestion\Contracts\OcrEngine;
use App\Domain\Ingestion\DTOs\ExtractedPage;

/**
 * Offline OCR stand-in for tests and for development machines without
 * Tesseract. Produces deterministic, structured, chunkable text so the rest of
 * the pipeline is genuinely exercised rather than skipped.
 */
final class FakeOcrEngine implements OcrEngine
{
    /**
     * 12 pages across 4 lessons of 3 pages each. A real state-board lesson
     * runs 8–10 pages and yields 15–25 chunks; this is the smallest fixture
     * that still clears the retrieval coverage bar honestly, rather than
     * lowering the bar to fit the fixture.
     */
    public function __construct(private readonly int $pages = 12) {}

    public function name(): string
    {
        return 'fake';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /** @return list<ExtractedPage> */
    public function extract(string $absolutePath, array $languageHints = ['en']): array
    {
        $pages = [];

        for ($page = 1; $page <= $this->pages; $page++) {
            $pages[] = new ExtractedPage(
                pageNumber: $page,
                text: $this->pageText($page),
                confidence: 0.97,
                engine: $this->name(),
            );
        }

        return $pages;
    }

    /**
     * A realistic page: a heading, several distinct paragraphs, a callout and
     * a book-back exercise block. Enough material that a lesson clears the
     * retrieval coverage bar, as a real textbook lesson would.
     */
    private function pageText(int $page): string
    {
        $unit = (int) ceil($page / 6);
        $lesson = (int) ceil($page / 3);

        $paragraphs = [
            'The circulatory system carries blood to every part of the body. '
                .'Blood transports oxygen, digested food and hormones to the cells, '
                .'and carries away carbon dioxide and other wastes. Without this '
                .'continuous transport the cells of the body would quickly die. '
                .'The system consists of the heart, the blood vessels and the blood itself.',

            'The heart is a muscular organ located slightly to the left of the '
                .'chest cavity. It is roughly the size of a closed fist and is '
                .'protected by the rib cage. The human heart has four chambers: two '
                .'atria above and two ventricles below. The atria receive blood '
                .'returning to the heart, while the ventricles pump blood out of it.',

            'It pumps blood through blood vessels called arteries and veins. '
                .'Arteries carry blood away from the heart and have thick elastic '
                .'walls to withstand pressure. Veins return blood to the heart and '
                .'contain valves that prevent blood from flowing backwards. The '
                .'smallest vessels, the capillaries, allow exchange with the tissues.',

            'Blood is a fluid connective tissue made of plasma and blood cells. '
                .'Red blood cells contain haemoglobin, which is what binds oxygen '
                .'and gives blood its colour. White blood cells defend the body '
                .'against disease, and platelets help the blood to clot when a '
                .'vessel is injured.',
        ];

        return implode("\n\n", [
            "Unit {$unit} — The Living World",
            "Lesson {$lesson}.1 Circulatory System",
            ...$paragraphs,
            'Important: The heart beats continuously throughout life, about 72 times '
                .'per minute in a healthy adult at rest.',
            'Exercise',
            "1. Name the organ that pumps blood.\n"
                ."2. How many chambers does the human heart have?\n"
                .'3. State one difference between an artery and a vein.',
        ]);
    }
}
