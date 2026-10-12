<?php

declare(strict_types=1);

return [

    /*
    | fake — deterministic hash vectors, no model server
    | http — self-hosted multilingual-e5-large behind an HTTP endpoint
    */
    'driver' => env('EMBEDDING_DRIVER', 'fake'),

    'model' => env('EMBEDDING_MODEL', 'multilingual-e5-large'),

    /*
    | pgvector columns have a fixed dimensionality, so changing this is a
    | migration, not a config flip. Pin one model per index generation.
    */
    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1024),

    'endpoint' => env('EMBEDDING_ENDPOINT', 'http://127.0.0.1:8080/embed'),

    'timeout_seconds' => 120,

    'retrieval' => [
        'vector_k' => 40,
        'keyword_k' => 40,
        'rrf_k' => 60,
        'rerank_top_n' => 12,
        'max_context_tokens' => 6000,
        'min_similarity' => 0.62,

        /*
        | Below this many retrieved chunks a topic is treated as NOT COVERED by
        | the uploaded sources, and generation is refused rather than allowed
        | to invent. The refusal becomes a coverage_gaps row — actionable
        | content work instead of a silent hallucination.
        */
        'require_chunks' => 3,
    ],

];
