<?php

use App\Domains\Identity\IdentityServiceProvider;
use App\Domains\Seo\SeoServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SeoServiceProvider::class,
    IdentityServiceProvider::class,
];
