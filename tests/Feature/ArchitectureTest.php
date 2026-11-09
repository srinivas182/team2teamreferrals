<?php

arch('no debugging calls are left in code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('env() is only read inside config files')
    ->expect('env')
    ->not->toBeUsedIn('App');

arch('domain code does not depend on HTTP controllers')
    ->expect('App\Domains')
    ->not->toUse('App\Http\Controllers');

arch('value objects are immutable')
    ->expect('App\Domains\Seo\SeoData')
    ->toBeReadonly()
    ->toBeFinal();
