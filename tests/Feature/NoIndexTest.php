<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('the noindex alias adds the robots header', function () {
    Route::get('/_noindex', fn () => 'ok')->middleware('noindex');

    $this->get('/_noindex')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

test('robots txt disallows private paths and points to the sitemap', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())->toContain('User-agent: *')
        ->toContain('Disallow: /admin')
        ->toContain('Disallow: /account')
        ->toContain('Disallow: /user')
        ->toContain('Disallow: /api')
        ->toContain('Disallow: /login/link')
        ->toContain('Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml');
});
