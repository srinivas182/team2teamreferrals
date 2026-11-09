<?php

use Illuminate\Support\Facades\Route;

it('sends secure default headers with a nonce-based CSP', function () {
    $response = $this->get('/');
    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");
});

it('reports healthy on /up', function () {
    $this->get('/up')->assertOk();
});

it('renders a branded, noindex 404 page', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404)->where('seo.robots', 'noindex, nofollow'));
});

it('renders a branded maintenance page', function () {
    Route::get('/__maintenance-test', fn () => abort(503));
    $this->get('/__maintenance-test')->assertStatus(503)->assertInertia(fn ($page) => $page->component('Error')->where('status', 503));
});
