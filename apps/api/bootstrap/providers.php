<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\DocumentServiceProvider;
use App\Providers\IngestionServiceProvider;

return [
    AppServiceProvider::class,
    AiServiceProvider::class,
    IngestionServiceProvider::class,
    DocumentServiceProvider::class,
];
