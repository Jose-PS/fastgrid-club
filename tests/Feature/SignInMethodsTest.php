<?php

declare(strict_types=1);

use App\Actions\Auth\SignInMethods;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;

function addPasskey(User $user): Passkey
{
    return $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => Str::random(32),
        'credential' => [],
    ]);
}

test('the only steam identity cannot be removed', function () {
    $user = User::factory()->withoutEmail()->withSteam()->create();

    expect(app(SignInMethods::class)->canRemove($user, $user->identities()->sole()))->toBeFalse();
});

test('the only google identity cannot be removed', function () {
    $user = User::factory()->withoutEmail()->withGoogle()->create();

    expect(app(SignInMethods::class)->canRemove($user, $user->identities()->sole()))->toBeFalse();
});

test('the only passkey cannot be removed', function () {
    $user = User::factory()->withoutEmail()->create();
    $passkey = addPasskey($user);

    expect(app(SignInMethods::class)->canRemove($user, $passkey))->toBeFalse();
});

test('the only verified email cannot be removed', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    expect(app(SignInMethods::class)->canRemove($user, SignInMethods::Email))->toBeFalse();
});

test('an unverified email does not count as a method', function () {
    $user = User::factory()->withSteam()->create();
    $methods = app(SignInMethods::class);

    expect($methods->count($user))->toBe(1)
        ->and($methods->canRemove($user, $user->identities()->sole()))->toBeFalse();
});

test('any method can be removed when there are two', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam()->withGoogle()->create();
    $passkey = addPasskey($user);
    $methods = app(SignInMethods::class);

    expect($methods->count($user))->toBe(4)
        ->and($methods->canRemove($user, $user->identities()->firstOrFail()))->toBeTrue()
        ->and($methods->canRemove($user, $passkey))->toBeTrue()
        ->and($methods->canRemove($user, SignInMethods::Email))->toBeTrue();
});

test('each pair of methods allows removing one of them', function (string $first, string $second) {
    $factory = User::factory()->withoutEmail();

    foreach ([$first, $second] as $method) {
        $factory = match ($method) {
            'steam' => $factory->withSteam(),
            'google' => $factory->withGoogle(),
            'email' => $factory->state(['email' => fake()->unique()->safeEmail()])->withVerifiedEmail(),
            default => $factory,
        };
    }

    $user = $factory->create();

    if (in_array('passkey', [$first, $second], true)) {
        addPasskey($user);
    }

    $removable = match ($first) {
        'steam', 'google' => $user->identities()->firstOrFail(),
        'passkey' => $user->passkeys()->firstOrFail(),
        'email' => SignInMethods::Email,
    };

    expect(app(SignInMethods::class)->count($user))->toBe(2)
        ->and(app(SignInMethods::class)->canRemove($user, $removable))->toBeTrue();
})->with([
    ['steam', 'google'],
    ['steam', 'passkey'],
    ['google', 'email'],
    ['passkey', 'email'],
    ['email', 'steam'],
]);
