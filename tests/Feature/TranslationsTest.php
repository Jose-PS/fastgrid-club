<?php

declare(strict_types=1);

use App\Support\Translations;
use Inertia\Testing\AssertableInertia as Assert;

test('forGroups returns only the requested groups', function () {
    $lines = app(Translations::class)->forGroups(['nav', 'foot']);

    expect($lines)->not->toBeEmpty();

    foreach (array_keys($lines) as $key) {
        expect(str_starts_with($key, 'nav.') || str_starts_with($key, 'foot.'))->toBeTrue();
    }
});

test('forGroups does not match groups by prefix of the name', function () {
    expect(app(Translations::class)->forGroups(['na']))->toBe([]);
});

test('forGroups follows the current locale', function () {
    app()->setLocale('en');
    $english = app(Translations::class)->forGroups(['nav']);
    app()->setLocale('es');
    $spanish = app(Translations::class)->forGroups(['nav']);

    expect($english['nav.home'])->toBe('Home');
    expect($spanish['nav.home'])->toBe('Inicio');
});

test('the home page shares the locale, common groups and its own groups only', function () {
    $response = $this->get('/en');

    $response->assertInertia(fn (Assert $page) => $page->component('welcome')->where('locale', 'en'));

    $props = $response->inertiaProps();

    expect($props['translations']['nav.home'])->toBe('Home');
    expect($props['translations']['login.back'])->toBe('Back');
    expect($props['translations']['ui.theme'])->toBe('Switch theme');
    expect($props['translations'])->not->toHaveKey('hero.h1');
    expect(array_keys($props['translations']))->each->toMatch('/^(nav|foot|login|ui)\./');
    expect($props['pageTranslations']['hero.kicker'])->toBe('Assetto Corsa EVO community');
    expect($props['pageTranslations'])->not->toHaveKey('nav.home');
    expect($props['pageTranslations'])->not->toHaveKey('props.title');
    expect(array_keys($props['pageTranslations']))->each->toMatch('/^(hero|status|week|strip|cal|lb|res|donate)\./');
});

test('two consecutive requests in different languages are not mixed', function () {
    $english = $this->get('/en')->inertiaProps();
    $spanish = $this->get('/')->inertiaProps();

    expect($english['locale'])->toBe('en');
    expect($english['pageTranslations']['hero.h1'])->toBe('Race fair. Get faster.');
    expect($spanish['locale'])->toBe('es');
    expect($spanish['pageTranslations']['hero.h1'])->toBe('Corre limpio, sé más rápido.');
});
