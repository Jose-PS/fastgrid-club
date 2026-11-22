<?php

declare(strict_types=1);

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('app.debug', false);
    RateLimiter::clear('public');
});

function inertiaPage($response): array
{
    preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', (string) $response->getContent(), $match);

    return json_decode($match[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
}

function assertBrandedError($response, int $status, string $locale, string $title): void
{
    $response->assertStatus($status)->assertHeader('X-Robots-Tag', 'noindex');
    $html = (string) $response->getContent();
    $page = inertiaPage($response);

    expect($page['component'])->toBe('errors/error')
        ->and($page['props']['status'])->toBe($status)
        ->and($page['props']['locale'])->toBe($locale)
        ->and($page['props']['pageTranslations']["error.{$status}.title"])->toBe($title)
        ->and($page['props']['translations'])->toHaveKey('nav.home')
        ->and($html)->toContain('lang="'.$locale.'"')
        ->not->toContain('vendor/')
        ->not->toContain('Stack trace')
        ->not->toContain('SECRET-MESSAGE');
}

function registerBoomRoute(): void
{
    Route::middleware('web')->get('/_boom', fn () => throw new RuntimeException('SECRET-MESSAGE'));
}

test('404 renders the branded page in the cookie language', function (string $locale, string $title) {
    $response = $this->withCookie('locale', $locale)->get('/ruta-que-no-existe');

    assertBrandedError($response, 404, $locale, $title);
})->with([
    'es' => ['es', 'Esta pista no existe'],
    'en' => ['en', "This track doesn't exist"],
]);

test('404 follows the browser language when there is no cookie', function () {
    $response = $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')->get('/ruta-que-no-existe');

    assertBrandedError($response, 404, 'en', "This track doesn't exist");
});

test('419 renders when a web POST has no CSRF token', function (string $locale, string $title) {
    $this->app['env'] = 'production';

    $response = $this->withCookie('locale', $locale)->post('/locale', ['locale' => 'en']);

    assertBrandedError($response, 419, $locale, $title);
})->with([
    'es' => ['es', 'La sesión ha caducado'],
    'en' => ['en', 'Your session has expired'],
]);

test('429 renders the page and keeps Retry-After', function (string $locale, string $title) {
    foreach (range(1, 60) as $attempt) {
        $this->get('/')->assertOk();
    }

    $response = $this->withCookie('locale', $locale)->get($locale === 'en' ? '/en' : '/');

    assertBrandedError($response, 429, $locale, $title);
    expect($response->headers->has('Retry-After'))->toBeTrue();
})->with([
    'es' => ['es', 'Demasiadas peticiones'],
    'en' => ['en', 'Too many requests'],
]);

test('500 hides the exception message and internals', function (string $locale, string $title) {
    registerBoomRoute();

    $response = $this->withCookie('locale', $locale)->get('/_boom');

    assertBrandedError($response, 500, $locale, $title);
})->with([
    'es' => ['es', 'Algo ha fallado de nuestro lado'],
    'en' => ['en', 'Something went wrong on our side'],
]);

test('JSON requests keep their JSON errors', function () {
    registerBoomRoute();

    $this->getJson('/ruta-que-no-existe')->assertNotFound()->assertJsonStructure(['message']);
    $this->getJson('/api/no-existe')->assertNotFound()->assertJsonStructure(['message']);
    $this->getJson('/_boom')->assertStatus(500)->assertJsonStructure(['message']);
});

test('500 keeps the debug page when debug is on', function () {
    config()->set('app.debug', true);
    registerBoomRoute();

    $this->get('/_boom')->assertStatus(500)->assertSee('SECRET-MESSAGE', false);
});

test('a GET to a POST-only route answers 405 with Allow', function () {
    $response = $this->get('/logout');

    $response->assertStatus(405)->assertHeader('Allow', 'POST');
    expect((string) $response->getContent())->not->toContain('vendor/');
});
