<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('passkey routes are registered', function (string $name) {
    expect(Route::has($name))->toBeTrue();
})->with([
    'passkey.login-options',
    'passkey.login',
    'passkey.confirm-options',
    'passkey.confirm',
    'passkey.registration-options',
    'passkey.store',
    'passkey.update',
    'passkey.destroy',
    'well-known.passkeys',
]);

test('passkey endpoints document points to the account page', function () {
    $this->getJson('/.well-known/passkey-endpoints')
        ->assertOk()
        ->assertExactJson([
            'enroll' => route('account.show'),
            'manage' => route('account.show'),
        ]);
});

test('passkey login options are available to guests', function () {
    $this->getJson(route('passkey.login-options'))->assertOk();
});
