<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Domain\Documents\Contracts\PdfRenderer;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use RuntimeException;

/**
 * Pure-PHP renderer with Indic shaping.
 *
 * Tamil, Devanagari and the other Indic scripts are not simply "another
 * alphabet": glyphs reorder and combine, so a renderer that lays out
 * codepoints left to right produces text a Tamil reader cannot read. mPDF's
 * OTL layer applies the real shaping rules, which is why `useOTL` is enabled
 * rather than left at the default.
 */
final class MpdfRenderer implements PdfRenderer
{
    public function __construct(private readonly string $tempPath) {}

    public function name(): string
    {
        return 'mpdf';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{content: string, pages: int}
     */
    public function render(string $html, array $options = []): array
    {
        $mpdf = new Mpdf($this->config($options));

        $mpdf->SetTitle((string) ($options['title'] ?? 'ExamForge Document'));
        $mpdf->SetAuthor('ExamForge');
        $mpdf->SetCreator('ExamForge');

        if (isset($options['footer'])) {
            $mpdf->SetHTMLFooter((string) $options['footer']);
        }

        // Watermarks make a leaked paper traceable to the institute it came
        // from, which is the main deterrent against redistribution.
        if (! empty($options['watermark'])) {
            $mpdf->SetWatermarkText((string) $options['watermark']);
            $mpdf->showWatermarkText = true;
            $mpdf->watermarkTextAlpha = 0.07;
        }

        $mpdf->WriteHTML($html);

        $content = $mpdf->Output('', 'S');

        if ($content === '') {
            throw new RuntimeException('The PDF renderer produced no output.');
        }

        return ['content' => $content, 'pages' => $mpdf->page];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function config(array $options): array
    {
        $config = [
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 16,
            'margin_bottom' => 18,
            'margin_footer' => 8,
            'tempDir' => $this->tempPath,
            // 0 = off, 1 = OTL on. Required for Indic scripts; without it
            // Tamil ligatures render as disconnected codepoints.
            'useOTL' => 0xFF,
            'useKerning' => true,
            // mPDF maps each detected script to a font it ships. Tamil maps to
            // FreeSerif, which carries both Latin and full Tamil, so one font
            // sets an English instruction line and a Tamil question stem in the
            // same paragraph.
            //
            // Registering a system font here instead was a mistake worth
            // recording: Windows ships Nirmala only as a .ttc, and both it and
            // the current Noto Sans Tamil builds contain MarkGlyphSets, which
            // mPDF refuses outright — so the renderer either threw or dropped
            // every Tamil glyph, depending on which font it found.
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            // FreeSerif has Tamil in its regular face only, so Tamil goes to
            // a regular-only family and bold or italic Tamil falls back to
            // upright instead of printing nothing. See UprightTamilFont.
            'languageToFont' => new UprightTamilFont,
            // Passing fontdata replaces mPDF's table rather than extending
            // it, so the defaults are carried over explicitly.
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + UprightTamilFont::fontData(),
            // A question is a table kept on one page where it fits. When one
            // cannot fit even a whole page, mPDF's default is to shrink it
            // until it does — a long passage question came out in 5pt type.
            // This lets it break across pages at full size instead.
            'tableMinSizePriority' => true,
        ];

        return $config;
    }
}
