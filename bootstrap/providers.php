<?php

return [
    App\Domains\Identity\IdentityServiceProvider::class,
    App\Domains\Messaging\MessagingServiceProvider::class,
    App\Domains\Network\NetworkServiceProvider::class,
    App\Domains\Billing\BillingServiceProvider::class,
    App\Domains\Platform\PlatformServiceProvider::class,
    App\Domains\Seo\SeoServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
