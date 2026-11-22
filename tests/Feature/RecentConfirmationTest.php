<?php

declare(strict_types=1);

use App\Enums\LoginLinkPurpose;
use App\Mail\AccountLinkMail;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('sensitive actions are blocked without a recent confirmation', function (string $method, Closure $url) {
    $user = User::factory()->withVerifiedEmail()->withSteam()->create();
    $passkey = $user->passkeys()->create(['name' => 'Portátil', 'credential_id' => Str::random(32), 'credential' => []]);

    $this->actingAs($user)
        ->call($method, $url($passkey))
        ->assertRedirect(route('account.confirm.show'));

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en')
        ->json($method, $url($passkey))
        ->assertStatus(423)
        ->assertJson(['message' => 'Confirm it is you before continuing.']);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and($user->hasSteam())->toBeTrue()
        ->and($passkey->fresh())->not->toBeNull();
})->with([
    'unlink steam' => ['DELETE', fn () => route('account.identities.destroy', 'steam')],
    'delete passkey' => ['DELETE', fn ($passkey) => route('passkey.destroy', $passkey)],
    'delete account' => ['DELETE', fn () => route('account.destroy')],
]);

test('a confirmation older than ten minutes no longer counts', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->subMinutes(10)->subSecond()->getTimestamp()])
        ->delete(route('account.identities.destroy', 'steam'))
        ->assertRedirect(route('account.confirm.show'));

    expect($user->fresh()->hasSteam())->toBeTrue();
});

test('a recent sign in counts as confirmation', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam()->create(['email' => 'ana@example.test']);
    $token = Str::random(64);
    LoginLink::factory()->create(['email' => 'ana@example.test', 'token_hash' => hash('sha256', $token)]);

    $this->post(route('login.link.consume', $token))->assertRedirect();
    $this->assertAuthenticatedAs($user);

    $this->delete(route('account.identities.destroy', 'steam'))->assertRedirect(route('account.show'));

    expect($user->fresh()->hasSteam())->toBeFalse();
});

test('the recent sign in stops counting after ten minutes', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam()->create(['email' => 'ana@example.test']);
    $token = Str::random(64);
    LoginLink::factory()->create(['email' => 'ana@example.test', 'token_hash' => hash('sha256', $token)]);

    $this->post(route('login.link.consume', $token))->assertRedirect();
    $this->travel(11)->minutes();

    $this->delete(route('account.identities.destroy', 'steam'))->assertRedirect(route('account.confirm.show'));
});

test('the confirmation page offers the methods the user has', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam()->create();

    $this->actingAs($user)
        ->get(route('account.confirm.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/confirm')
            ->where('methods', ['passkey' => false, 'steam' => true, 'google' => false, 'email' => true])
            ->where('minutes', 10));
});

test('a confirmation link by email marks the confirmation once', function () {
    Mail::fake();
    $user = User::factory()->withVerifiedEmail()->withSteam()->create();
    $this->actingAs($user);

    $this->post(route('account.confirm.link'))->assertRedirect(route('account.confirm.show'));

    $token = null;
    Mail::assertQueued(AccountLinkMail::class, function (AccountLinkMail $mail) use (&$token, $user): bool {
        $token = basename($mail->url);

        return $mail->hasTo($user->email) && $mail->purpose === LoginLinkPurpose::Confirmation;
    });

    expect(LoginLink::query()->sole()->token_hash)->toBe(hash('sha256', $token));

    $this->get(route('account.link.show', $token))
        ->assertInertia(fn (Assert $page) => $page->component('account/link')->where('purpose', 'confirmation'));
    expect(session('auth.password_confirmed_at'))->toBeNull();

    $this->post(route('account.link.consume', $token))->assertRedirect(route('account.show'));
    expect(session('auth.password_confirmed_at'))->toBeInt();

    $this->delete(route('account.identities.destroy', 'steam'))->assertRedirect(route('account.show'));
    expect($user->fresh()->hasSteam())->toBeFalse();

    $this->get(route('account.link.show', $token))
        ->assertInertia(fn (Assert $page) => $page->where('purpose', null)->where('consumeUrl', null));
});

test('a confirmation link of another user does not confirm', function () {
    Mail::fake();
    $owner = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($owner)->post(route('account.confirm.link'));

    $token = null;
    Mail::assertQueued(AccountLinkMail::class, function (AccountLinkMail $mail) use (&$token): bool {
        $token = basename($mail->url);

        return true;
    });

    $intruder = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($intruder)->withSession(['auth.password_confirmed_at' => null]);

    $this->post(route('account.link.consume', $token))->assertRedirect(route('account.link.show', $token));

    expect(session('auth.password_confirmed_at'))->toBeNull()
        ->and(LoginLink::query()->sole()->used_at)->toBeNull();
});

test('a login link cannot be used as a confirmation link', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $token = Str::random(64);
    LoginLink::factory()->create(['email' => $user->email, 'token_hash' => hash('sha256', $token)]);

    $this->actingAs($user)->post(route('account.link.consume', $token))->assertRedirect(route('account.link.show', $token));

    expect(session('auth.password_confirmed_at'))->toBeNull();
});
