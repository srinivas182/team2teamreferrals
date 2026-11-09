<?php

use App\Domains\Identity\IdentityServiceProvider;
use App\Domains\Messaging\MessagingServiceProvider;
use App\Domains\Platform\PlatformServiceProvider;
use App\Domains\Seo\SeoServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SeoServiceProvider::class,
    PlatformServiceProvider::class,
    IdentityServiceProvider::class,
    MessagingServiceProvider::class,
];
