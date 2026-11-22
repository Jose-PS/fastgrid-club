<?php

declare(strict_types=1);

use App\Enums\IdentityProvider;
use App\Events\SteamUnlinked;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
});

test('the only steam or google identity cannot be unlinked', function (string $provider) {
    $factory = User::factory()->withoutEmail();
    $user = ($provider === 'steam' ? $factory->withSteam() : $factory->withGoogle())->create();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en')
        ->deleteJson(route('account.identities.destroy', $provider))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identity' => 'You cannot remove your only sign-in method. Add another one first.']);

    expect($user->identities()->count())->toBe(1);
})->with(['steam', 'google']);

test('an identity can be unlinked when another method remains', function (string $provider, string $other) {
    $factory = User::factory()->withoutEmail();
    $factory = $provider === 'steam' ? $factory->withSteam() : $factory->withGoogle();
    $factory = match ($other) {
        'email' => $factory->state(['email' => fake()->unique()->safeEmail()])->withVerifiedEmail(),
        'steam' => $factory->withSteam(),
        'google' => $factory->withGoogle(),
        default => $factory,
    };
    $user = $factory->create();

    if ($other === 'passkey') {
        $user->passkeys()->create(['name' => 'Portátil', 'credential_id' => Str::random(32), 'credential' => []]);
    }

    $sessionId = session()->getId();

    $this->actingAs($user)
        ->delete(route('account.identities.destroy', $provider))
        ->assertRedirect(route('account.show'))
        ->assertSessionHasNoErrors();

    expect($user->identities()->where('provider', $provider)->exists())->toBeFalse()
        ->and(session()->getId())->not->toBe($sessionId);
})->with([
    ['steam', 'email'],
    ['steam', 'google'],
    ['steam', 'passkey'],
    ['google', 'email'],
    ['google', 'steam'],
    ['google', 'passkey'],
]);

test('an unverified email does not count as the remaining method', function () {
    $user = User::factory()->withSteam()->create(['email_verified_at' => null]);

    $this->actingAs($user)
        ->deleteJson(route('account.identities.destroy', 'steam'))
        ->assertUnprocessable();

    expect($user->hasSteam())->toBeTrue();
});

test('unlinking steam dispatches the steam unlinked event with the steam id', function () {
    Event::fake([SteamUnlinked::class]);
    $user = User::factory()->withVerifiedEmail()->withSteam('76561198000000042')->create();

    $this->actingAs($user)->delete(route('account.identities.destroy', 'steam'))->assertRedirect(route('account.show'));

    Event::assertDispatched(SteamUnlinked::class, fn (SteamUnlinked $event): bool => $event->userId === $user->id
        && $event->steamId === '76561198000000042');
});

test('unlinking google does not dispatch the steam unlinked event', function () {
    Event::fake([SteamUnlinked::class]);
    $user = User::factory()->withVerifiedEmail()->withGoogle()->create();

    $this->actingAs($user)->delete(route('account.identities.destroy', 'google'))->assertRedirect(route('account.show'));

    Event::assertNotDispatched(SteamUnlinked::class);
});

test('unlinking a provider that is not linked answers not found', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    $this->actingAs($user)->deleteJson(route('account.identities.destroy', 'steam'))->assertNotFound();
    $this->actingAs($user)->deleteJson('/account/identities/discord')->assertNotFound();
});

test('unlinking only touches the identities of the signed in user', function () {
    $other = User::factory()->withVerifiedEmail()->withSteam()->create();
    $user = User::factory()->withVerifiedEmail()->withSteam()->create();

    $this->actingAs($user)->delete(route('account.identities.destroy', 'steam'))->assertRedirect(route('account.show'));

    expect($other->fresh()->hasSteam())->toBeTrue()
        ->and(UserIdentity::query()->where('provider', IdentityProvider::Steam)->count())->toBe(1);
});

test('guests cannot unlink identities', function () {
    $this->deleteJson(route('account.identities.destroy', 'steam'))->assertUnauthorized();
});
