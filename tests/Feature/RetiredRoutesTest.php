<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Route;

test('retired routes are not registered', function (string $method, string $uri) {
    $registered = collect(Route::getRoutes()->getRoutes())->contains(
        fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true),
    );

    expect($registered)->toBeFalse();
})->with([
    'password login' => ['POST', 'login'],
    'register form' => ['GET', 'register'],
    'register' => ['POST', 'register'],
    'forgot password form' => ['GET', 'forgot-password'],
    'forgot password' => ['POST', 'forgot-password'],
    'reset password' => ['POST', 'reset-password'],
    'two factor challenge' => ['GET', 'two-factor-challenge'],
    'two factor login' => ['POST', 'two-factor-challenge'],
    'confirm password' => ['GET', 'user/confirm-password'],
    'confirm password store' => ['POST', 'user/confirm-password'],
    'email verification notice' => ['GET', 'email/verify'],
    'dashboard' => ['GET', 'dashboard'],
    'settings profile' => ['GET', 'settings/profile'],
]);

test('retired routes answer not found', function (string $method, string $uri) {
    $this->call($method, $uri)->assertNotFound();
})->with([
    ['GET', '/register'],
    ['GET', '/forgot-password'],
    ['GET', '/two-factor-challenge'],
    ['GET', '/user/confirm-password'],
    ['GET', '/email/verify'],
    ['GET', '/dashboard'],
    ['GET', '/settings/profile'],
]);

test('there is no password login handler', function () {
    User::factory()->withVerifiedEmail()->create(['email' => 'ana@example.test']);

    $status = $this->post('/login', ['email' => 'ana@example.test', 'password' => 'password'])->getStatusCode();

    expect($status)->toBeIn([404, 405]);
    $this->assertGuest();
    expect(session()->has('login_web_'.sha1(SessionGuard::class)))->toBeFalse();
});
