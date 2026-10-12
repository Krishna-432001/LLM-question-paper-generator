<?php

declare(strict_types=1);

return [

    /*
    | fake        — offline, deterministic, no binaries required
    | tesseract   — local, compute-only cost, weaker on Tamil
    | document_ai — Google Document AI, best Tamil accuracy (Phase 2b)
    */
    'ocr_driver' => env('OCR_DRIVER', 'fake'),

    /*
    | Malware scan, the first ingestion stage.
    |
    | none   — records "not_scanned"; refused when APP_ENV=production
    | fake   — flags the EICAR test string only; for tests
    | clamav — clamd over TCP (INSTREAM). Raise clamd's StreamMaxLength to
    |          at least examforge.uploads.max_bytes, or large books fail.
    */
    'antivirus' => [
        'driver' => env('AV_DRIVER', 'none'),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout_seconds' => (int) env('CLAMAV_TIMEOUT', 120),
        ],
    ],

    'poppler' => [
        'pdftotext' => env('PDFTOTEXT_BIN', 'pdftotext'),
        'pdftoppm' => env('PDFTOPPM_BIN', 'pdftoppm'),
    ],

    'tesseract' => [
        'binary' => env('TESSERACT_BIN', 'tesseract'),
        'dpi' => 300,
    ],

    /*
    | A PDF is treated as digital (no OCR needed) when at least this share of
    | pages already carry >=100 characters of embedded text. This branch alone
    | removes roughly 60% of OCR spend on real state-board PDFs.
    */
    'text_layer_page_ratio' => 0.80,

    /*
    | Pages below this OCR confidence are quarantined (needs_review) and
    | excluded from chunking. Losing a page costs one page; trusting a bad one
    | poisons every question generated from it.
    */
    'min_page_confidence' => 0.80,

    'extract_timeout_seconds' => 900,

    'chunking' => [
        'target_tokens' => 450,
        'max_tokens' => 600,
        'min_tokens' => 120,
        'overlap_ratio' => 0.15,
        'embed_batch_size' => 64,
    ],

    /*
    | Structure detection uses free heuristics first and a cheap model only to
    | repair the candidate list. Below this confidence the LLM repair pass runs.
    */
    'structure' => [
        'heuristic_confidence_threshold' => 0.75,
        'min_candidates_for_llm_repair' => 3,
    ],

];
