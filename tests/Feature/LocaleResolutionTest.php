<?php

declare(strict_types=1);

use App\Models\User;

function htmlLang(string $html): string
{
    preg_match('/<html lang="([^"]*)"/', $html, $matches);

    return $matches[1];
}

test('the default locale is spanish without any hint', function () {
    $response = $this->withHeader('Accept-Language', '')->get('/nope');

    expect(htmlLang($response->getContent()))->toBe('es');
});

test('the user locale is used', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $response = $this->actingAs($user)->withHeader('Accept-Language', '')->get('/nope');

    expect(htmlLang($response->getContent()))->toBe('en');
});

test('the cookie locale is used', function () {
    $response = $this->withCookie('locale', 'en')->withHeader('Accept-Language', 'es')->get('/nope');

    expect(htmlLang($response->getContent()))->toBe('en');
});

test('the accept language header is used', function (string $header, string $expected) {
    $response = $this->withHeader('Accept-Language', $header)->get('/nope');

    expect(htmlLang($response->getContent()))->toBe($expected);
})->with([
    ['en', 'en'],
    ['en-GB', 'en'],
    ['en-US,en;q=0.9,es;q=0.8', 'en'],
    ['es-ES,es;q=0.9', 'es'],
    ['fr-FR,en;q=0.5', 'en'],
    ['fr', 'es'],
    ['fr-FR,de;q=0.8', 'es'],
]);

test('an invalid cookie is ignored and the next source is used', function () {
    $response = $this->withCookie('locale', 'fr')
        ->withHeader('Accept-Language', 'en')
        ->get('/nope');

    expect(htmlLang($response->getContent()))->toBe('en');
});

test('an invalid user locale is ignored', function () {
    $user = User::factory()->create(['locale' => 'fr']);

    $response = $this->actingAs($user)
        ->withCookie('locale', 'en')
        ->withHeader('Accept-Language', '')
        ->get('/nope');

    expect(htmlLang($response->getContent()))->toBe('en');
});

test('the user locale wins over the cookie', function () {
    $user = User::factory()->create(['locale' => 'es']);

    $response = $this->actingAs($user)
        ->withCookie('locale', 'en')
        ->withHeader('Accept-Language', 'en')
        ->get('/nope');

    expect(htmlLang($response->getContent()))->toBe('es');
});

test('the cookie wins over the header', function () {
    $response = $this->withCookie('locale', 'es')
        ->withHeader('Accept-Language', 'en')
        ->get('/nope');

    expect(htmlLang($response->getContent()))->toBe('es');
});

test('consecutive requests do not leak the locale', function () {
    $first = $this->withHeader('Accept-Language', 'en')->get('/nope');
    $second = $this->withHeader('Accept-Language', '')->get('/nope');
    $third = $this->withHeader('Accept-Language', 'en')->get('/nope');
    $fourth = $this->withHeader('Accept-Language', '')->get('/nope');

    expect(htmlLang($first->getContent()))->toBe('en');
    expect(htmlLang($second->getContent()))->toBe('es');
    expect(htmlLang($third->getContent()))->toBe('en');
    expect(htmlLang($fourth->getContent()))->toBe('es');
});
