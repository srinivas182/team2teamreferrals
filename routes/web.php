<?php

use App\Domains\Seo\Schema;
use App\Domains\Seo\SeoData;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home', [
    'seo' => (new SeoData(
        title: 'Team to Team Referrals — hand out-of-area leads to teams you trust',
        description: (string) config('seo.default_description'),
        path: '/',
        schema: [Schema::organization(), Schema::website(), Schema::faq(array_map(fn ($x) => [$x['q'], $x['a']], App\Domains\Seo\Aeo::faq()))],
    ))->toArray(),
    'faq' => App\Domains\Seo\Aeo::faq(),
]))->middleware('edge.cache')->name('home');
