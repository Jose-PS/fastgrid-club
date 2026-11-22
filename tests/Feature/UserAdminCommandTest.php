<?php

declare(strict_types=1);

use App\Models\User;

test('the admin role is granted by id', function () {
    $user = User::factory()->create();

    $this->artisan('user:admin', ['user' => (string) $user->id])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('the admin role is granted by email', function () {
    $user = User::factory()->create(['email' => 'pilot@example.com']);

    $this->artisan('user:admin', ['user' => 'Pilot@Example.com'])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('the admin role is granted by steam id', function () {
    $user = User::factory()->withSteam('76561198000000003')->create();

    $this->artisan('user:admin', ['user' => '76561198000000003'])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('the revoke option removes the admin role', function () {
    $user = User::factory()->admin()->create();

    $this->artisan('user:admin', ['user' => (string) $user->id, '--revoke' => true])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('an unknown user fails', function (string $reference) {
    $this->artisan('user:admin', ['user' => $reference])->assertFailed();
})->with(['999999', 'nobody@example.com', '76561198000009999', 'not-a-reference']);
