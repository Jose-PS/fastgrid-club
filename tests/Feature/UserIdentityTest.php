<?php

declare(strict_types=1);

use App\Enums\IdentityProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;

test('a steam id cannot belong to two users', function () {
    User::factory()->withSteam('76561198000000001')->create();

    UserIdentity::factory()->for(User::factory())->steam()->create([
        'provider_id' => '76561198000000001',
    ]);
})->throws(UniqueConstraintViolationException::class);

test('a user cannot have two identities of the same provider', function () {
    $user = User::factory()->withGoogle()->create();

    UserIdentity::factory()->for($user)->google()->create();
})->throws(UniqueConstraintViolationException::class);

test('the steam id is exposed through the user', function () {
    $user = User::factory()->withSteam('76561198000000002')->create();
    $withoutSteam = User::factory()->withGoogle()->create();

    expect($user->hasSteam())->toBeTrue()
        ->and($user->steam_id)->toBe('76561198000000002')
        ->and($withoutSteam->hasSteam())->toBeFalse()
        ->and($withoutSteam->steam_id)->toBeNull()
        ->and($withoutSteam->identities()->sole()->provider)->toBe(IdentityProvider::Google);
});

test('the admin flag is not mass assignable', function () {
    $user = User::factory()->create();

    $user->fill(['is_admin' => true])->save();

    expect($user->fresh()->is_admin)->toBeFalse();
});
