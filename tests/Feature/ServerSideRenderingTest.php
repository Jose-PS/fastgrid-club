<?php

declare(strict_types=1);

test('server side rendering is disabled in the test environment', function () {
    $this->get('/')->assertOk()->assertDontSee('data-server-rendered', false);
});

test('the first html carries a noscript notice in the visitor language', function (string $locale, string $message) {
    $this->get($locale === 'en' ? '/en' : '/')
        ->assertOk()
        ->assertSee('<noscript>', false)
        ->assertSee($message);
})->with([
    'spanish' => ['es', 'necesitan JavaScript'],
    'english' => ['en', 'need JavaScript'],
]);

test('the noscript notice does not use inline styles blocked by the csp', function () {
    $html = $this->get('/')->getContent();

    preg_match('/<noscript>(.*?)<\/noscript>/s', $html, $matches);

    expect($matches[1] ?? '')->not->toContain('style=');
});

test('the ssr bundle is ignored by git', function () {
    expect(file_get_contents(base_path('.gitignore')))->toContain('/bootstrap/ssr');
});
