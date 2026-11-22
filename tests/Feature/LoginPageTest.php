<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the login page renders with no-store and its translations', function () {
    $response = $this->withHeader('Accept-Language', 'en')->get(route('login'));

    $response->assertOk()
        ->assertHeader('Cache-Control')
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->inertiaProps()['translations'])->toHaveKeys([
            'login.title', 'login.steam', 'login.google', 'login.email', 'login.emailBtn', 'login.passkey', 'login.note', 'login.back',
        ])
        ->and($response->inertiaProps()['translations']['login.title'])->toBe('Sign in');
});

test('an authenticated user is redirected away from login', function () {
    $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect();
});

test('an external redirect query is ignored', function () {
    $this->get(route('login', ['redirect' => 'https://evil.test/x']))->assertOk();

    expect(session('url.intended'))->toBeNull();
});

test('an array redirect query is ignored', function () {
    $this->get('/login?redirect[]=/proposals')->assertOk();

    expect(session('url.intended'))->toBeNull();
});

test('an internal redirect query is remembered', function () {
    $this->get(route('login', ['redirect' => '/proposals']))->assertOk();

    expect(session('url.intended'))->toBe('/proposals');
});
