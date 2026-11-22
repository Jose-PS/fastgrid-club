<?php

declare(strict_types=1);

use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Str;

test('signing out other sessions keeps the current one and closes the others', function () {
    $user = User::factory()->withVerifiedEmail()->create(['remember_token' => 'old-remember-token']);

    $this->actingAs($user)
        ->withSession(['auth.session_version' => 0])
        ->post(route('account.sessions.destroy'))
        ->assertRedirect(route('account.show'));

    $user->refresh();
    expect($user->session_version)->toBe(1)
        ->and($user->remember_token)->not->toBe('old-remember-token')
        ->and(session('auth.session_version'))->toBe(1);

    $this->get(route('account.show'))->assertOk();
    $this->assertAuthenticatedAs($user);

    $this->flushSession();

    $this->actingAs($user->fresh())
        ->withSession(['auth.session_version' => 0])
        ->get(route('account.show'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a session without a stored version adopts the current one', function () {
    $user = User::factory()->withVerifiedEmail()->create(['session_version' => 3]);

    $this->actingAs($user)->get(route('account.show'))->assertOk();

    expect(session('auth.session_version'))->toBe(3);
});

test('signing in stores the current session version', function () {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'ana@example.test', 'session_version' => 2]);
    $token = Str::random(64);
    LoginLink::factory()->create(['email' => 'ana@example.test', 'token_hash' => hash('sha256', $token)]);

    $this->post(route('login.link.consume', $token))->assertRedirect();

    expect(session('auth.session_version'))->toBe(2);
});

test('guests cannot sign out other sessions', function () {
    $this->postJson(route('account.sessions.destroy'))->assertUnauthorized();
});
