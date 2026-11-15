<?php

return [
    App\Domains\Identity\IdentityServiceProvider::class,
    App\Domains\Messaging\MessagingServiceProvider::class,
    App\Domains\Network\NetworkServiceProvider::class,
    App\Domains\Content\ContentServiceProvider::class,
    App\Domains\Billing\BillingServiceProvider::class,
    App\Domains\Community\CommunityServiceProvider::class,
    App\Domains\Platform\PlatformServiceProvider::class,
    App\Domains\Seo\SeoServiceProvider::class,
    App\Domains\Api\ApiServiceProvider::class,
    App\Domains\Integrations\IntegrationsServiceProvider::class,
    App\Domains\Growth\GrowthServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
