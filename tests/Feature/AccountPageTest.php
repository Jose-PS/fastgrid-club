<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the account page requires a session', function () {
    $this->get(route('account.show'))->assertRedirect(route('login'));
});

test('the account page lists only the passkeys of the user with noindex and no-store', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $user->passkeys()->create(['name' => 'Portátil', 'credential_id' => Str::random(32), 'credential' => []]);
    User::factory()->withVerifiedEmail()->create()
        ->passkeys()->create(['name' => 'Ajena', 'credential_id' => Str::random(32), 'credential' => []]);

    $response = $this->actingAs($user)->get(route('account.show'));

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/show')
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'Portátil')
            ->where('passkeys.0.lastUsedAt', null)
            ->has('passkeys.0.createdAt'));

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->inertiaProps()['pageTranslations'])->toHaveKey('account.passkeys.title');
});

test('the account page shows the sign in methods, the profile and the steam requirement', function () {
    $user = User::factory()->withVerifiedEmail()->withGoogle()->create([
        'name' => 'Max Piloto',
        'email' => 'max@example.test',
        'locale' => 'en',
        'show_in_rankings' => false,
    ]);
    $user->identities()->sole()->update(['email' => 'max.google@example.test']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(route('account.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/show')
            ->where('methods.steam', null)
            ->where('methods.google.label', 'max.google@example.test')
            ->where('methods.google.canUnlink', true)
            ->has('methods.google.linkedAt')
            ->where('methods.email.address', 'max@example.test')
            ->has('methods.email.verifiedAt')
            ->where('profile', ['name' => 'Max Piloto', 'locale' => 'en', 'showInRankings' => false])
            ->where('locales', ['es', 'en'])
            ->whereNot('confirmedUntil', null)
            ->where('pageTranslations', fn ($translations) => isset($translations['account.methods.steamMissing'], $translations['account.danger.title'])));
});

test('the only method cannot be unlinked from the page', function () {
    $user = User::factory()->withoutEmail()->withSteam()->create();

    $this->actingAs($user)
        ->get(route('account.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('methods.steam.canUnlink', false)
            ->where('methods.email', null)
            ->where('confirmedUntil', null));
});
