<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;

function createPasskeyFor(User $user, string $name = 'Portátil'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => Str::random(32),
        'credential' => [],
    ]);
}

test('a passkey can be renamed with a valid alias', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $passkey = createPasskeyFor($user);

    $this->actingAs($user)
        ->from(route('account.show'))
        ->patch(route('passkey.update', $passkey), ['name' => 'Móvil'])
        ->assertRedirect(route('account.show'))
        ->assertSessionHasNoErrors();

    expect($passkey->fresh()->name)->toBe('Móvil');
});

test('a passkey alias must be between 1 and 40 characters', function (string $name) {
    $user = User::factory()->withVerifiedEmail()->create();
    $passkey = createPasskeyFor($user);

    $this->actingAs($user)
        ->patchJson(route('passkey.update', $passkey), ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect($passkey->fresh()->name)->toBe('Portátil');
})->with(['', '   ', str_repeat('a', 41)]);

test('an alias of 40 characters is accepted', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $passkey = createPasskeyFor($user);

    $this->actingAs($user)
        ->patchJson(route('passkey.update', $passkey), ['name' => str_repeat('a', 40)])
        ->assertRedirect();

    expect($passkey->fresh()->name)->toBe(str_repeat('a', 40));
});

test('the only sign in method cannot be deleted', function () {
    $user = User::factory()->withoutEmail()->create();
    $passkey = createPasskeyFor($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->withHeader('Accept-Language', 'en')
        ->deleteJson(route('passkey.destroy', $passkey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['passkey' => 'You cannot delete your only sign-in method. Add another one first.']);

    expect($passkey->fresh())->not->toBeNull();
});

test('a passkey can be deleted when another method remains', function (string $method) {
    $factory = User::factory()->withoutEmail();
    $factory = match ($method) {
        'email' => $factory->state(['email' => fake()->unique()->safeEmail()])->withVerifiedEmail(),
        'steam' => $factory->withSteam(),
        default => $factory,
    };
    $user = $factory->create();
    $passkey = createPasskeyFor($user);

    if ($method === 'passkey') {
        createPasskeyFor($user, 'Móvil');
    }

    $sessionId = session()->getId();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->from(route('account.show'))
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect(route('account.show'))
        ->assertSessionHasNoErrors();

    expect($passkey->fresh())->toBeNull()
        ->and(session()->getId())->not->toBe($sessionId);
})->with(['email', 'steam', 'passkey']);

test('a user cannot rename or delete the passkey of another user', function () {
    $owner = User::factory()->withVerifiedEmail()->create();
    $passkey = createPasskeyFor($owner);
    createPasskeyFor($owner, 'Móvil');
    $intruder = User::factory()->withVerifiedEmail()->create();

    $this->actingAs($intruder)
        ->patchJson(route('passkey.update', $passkey), ['name' => 'Robada'])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->deleteJson(route('passkey.destroy', $passkey))
        ->assertForbidden();

    expect($passkey->fresh()->name)->toBe('Portátil');
});

test('guests cannot rename or delete passkeys', function () {
    $passkey = createPasskeyFor(User::factory()->withVerifiedEmail()->create());

    $this->patchJson(route('passkey.update', $passkey), ['name' => 'Robada'])->assertUnauthorized();
    $this->deleteJson(route('passkey.destroy', $passkey))->assertUnauthorized();

    expect($passkey->fresh()->name)->toBe('Portátil');
});

test('deleting a passkey requires a recent confirmation', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $passkey = createPasskeyFor($user);

    $this->actingAs($user)
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect(route('account.confirm.show'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->subMinutes(11)->getTimestamp()])
        ->deleteJson(route('passkey.destroy', $passkey))
        ->assertStatus(423);

    expect($passkey->fresh())->not->toBeNull();
});
