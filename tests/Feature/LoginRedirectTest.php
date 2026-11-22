<?php

declare(strict_types=1);

use App\Actions\Auth\LoginRedirect;
use App\Models\User;

test('internal urls are accepted', function (string $url) {
    expect(app(LoginRedirect::class)->isInternal($url))->toBeTrue();
})->with([
    '/proposals',
    '/calendar?week=2',
    '/',
    'absolute url on the app host' => fn () => config('app.url').'/proposals',
    'upper case scheme and host' => fn () => strtoupper((string) config('app.url')).'/calendar',
]);

test('external or unsafe urls are rejected', function (string $url) {
    expect(app(LoginRedirect::class)->isInternal($url))->toBeFalse();
})->with([
    'https://evil.test/proposals',
    'https://fastgrid.test:8443/proposals',
    'https://fastgrid.test.evil.test/',
    'https://user@evil.test/',
    '//evil.test',
    '/\\evil.test',
    "/\t/evil.test",
    'javascript:alert(1)',
    'data:text/html,hi',
    'proposals',
    '',
]);

test('a remembered internal url is consumed once', function () {
    $redirect = app(LoginRedirect::class);

    $redirect->remember('/proposals');

    expect($redirect->redirect()->getTargetUrl())->toBe(url('/proposals'))
        ->and($redirect->redirect()->getTargetUrl())->toBe(route('home'));
});

test('an external url is ignored and falls back to home', function (string $url) {
    $redirect = app(LoginRedirect::class);

    $redirect->remember($url);

    expect($redirect->redirect()->getTargetUrl())->toBe(route('home'));
})->with(['https://evil.test/', '//evil.test', '/\\evil.test', 'javascript:alert(1)', 'ftp://localhost/']);

test('an unsafe intended url stored by other code is not followed', function () {
    session()->put('url.intended', '//evil.test');

    expect(app(LoginRedirect::class)->redirect()->getTargetUrl())->toBe(route('home'));
});

test('completing a login remembers the user, regenerates the session and redirects back', function () {
    $user = User::factory()->create();
    $redirect = app(LoginRedirect::class);
    $redirect->remember('/calendar');
    $sessionId = session()->getId();

    $response = $redirect->completeLogin($user);

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionId)
        ->and($user->fresh()->remember_token)->not->toBeNull()
        ->and($response->getTargetUrl())->toBe(url('/calendar'));
});
