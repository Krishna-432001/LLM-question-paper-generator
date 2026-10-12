<?php

declare(strict_types=1);

return [

    /*
    | mpdf — pure PHP with Indic (OTL) shaping. Works with no external binary,
    |        which is what makes Tamil papers renderable on any host.
    | fake — structurally valid PDF without a rendering engine, for tests.
    */
    'driver' => env('PDF_DRIVER', 'mpdf'),

    'disk' => env('PDF_DISK', 'local'),

    'temp_path' => storage_path('app/mpdf-temp'),

    /*
    | Bumping this invalidates every content hash, so all documents re-render
    | with the new layout. Old rows keep their payload, so a paper handed out
    | last term can still be reproduced exactly as it was.
    */
    'template_version' => 2,

];
