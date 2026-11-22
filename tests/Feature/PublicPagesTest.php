<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the calendar page renders its component with its own translations', function () {
    $response = $this->get(route('en.calendar'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('calendar'));

    expect($response->inertiaProps()['pageTranslations']['cal.title'])->toBe('Weekly calendar');
});

test('the proposals page renders its component with its own translations', function () {
    $response = $this->get(route('en.proposals'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('proposals'));

    expect($response->inertiaProps()['pageTranslations']['props.title'])->toBe('Proposals');
});

test('every public page shares the ui group', function (string $path) {
    $props = $this->get($path)->inertiaProps();

    expect($props['translations'])->toHaveKeys(['ui.theme', 'ui.lang', 'ui.tz', 'nav.home']);
})->with(['/', '/calendar', '/proposals']);

test('guests share a null user', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
});

test('a signed in user shares only its id and name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertInertia(
        fn (Assert $page) => $page->where('auth.user', ['id' => $user->id, 'name' => $user->name, 'is_admin' => false]),
    );
});

test('responses do not set the retired appearance cookie', function (string $path) {
    $this->get($path)->assertCookieMissing('appearance');
})->with(['/', '/calendar', '/proposals']);

test('the page shell applies the stored theme before the first paint', function () {
    $html = $this->get('/')->getContent();

    expect($html)->toContain("localStorage.getItem('sg-theme')")
        ->toContain('prefers-color-scheme: light')
        ->not->toContain('class="dark"');
});
