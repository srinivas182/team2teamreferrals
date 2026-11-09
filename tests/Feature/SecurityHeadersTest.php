<?php

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
