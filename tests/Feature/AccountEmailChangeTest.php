<?php

declare(strict_types=1);

use App\Enums\LoginLinkPurpose;
use App\Mail\AccountLinkMail;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('public');
});

function requestEmailChange(User $user, string $email): string
{
    $token = null;

    test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->post(route('account.email.update'), ['email' => $email])
        ->assertRedirect(route('account.show'))
        ->assertSessionHasNoErrors();

    $recipient = mb_strtolower(trim($email));

    Mail::assertQueued(AccountLinkMail::class, function (AccountLinkMail $mail) use (&$token, $recipient): bool {
        if (! $mail->hasTo($recipient)) {
            return false;
        }

        $token = basename($mail->url);

        return $mail->purpose === LoginLinkPurpose::EmailChange;
    });

    return (string) $token;
}

test('changing the email sends a link to the new address and keeps the old one until confirmed', function () {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);

    $token = requestEmailChange($user, '  New@Example.TEST ');

    $link = LoginLink::query()->sole();
    expect($link->email)->toBe('new@example.test')
        ->and($link->user_id)->toBe($user->id)
        ->and($link->token_hash)->toBe(hash('sha256', $token))
        ->and($user->fresh()->email)->toBe('old@example.test');

    $this->get(route('account.link.show', $token))
        ->assertInertia(fn (Assert $page) => $page->component('account/link')->where('purpose', 'email_change'));
    expect($user->fresh()->email)->toBe('old@example.test');

    $this->post(route('account.link.consume', $token))->assertRedirect(route('account.show'));

    $user->refresh();
    expect($user->email)->toBe('new@example.test')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('an email change link works only once', function () {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);
    $token = requestEmailChange($user, 'new@example.test');

    $this->post(route('account.link.consume', $token))->assertRedirect(route('account.show'));
    $user->forceFill(['email' => 'old@example.test'])->save();

    $this->post(route('account.link.consume', $token))->assertRedirect(route('account.link.show', $token));

    expect($user->fresh()->email)->toBe('old@example.test');
});

test('an email change link expires after fifteen minutes', function () {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);
    $token = requestEmailChange($user, 'new@example.test');

    $this->travel(16)->minutes();

    $this->post(route('account.link.consume', $token))->assertRedirect(route('account.link.show', $token));

    expect($user->fresh()->email)->toBe('old@example.test');
});

test('an email that belongs to another account is not taken and nothing changes', function () {
    User::factory()->withVerifiedEmail()->create(['email' => 'taken@example.test']);
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);
    $token = requestEmailChange($user, 'taken@example.test');

    $response = $this->post(route('account.link.consume', $token))->assertRedirect(route('account.show'));

    expect($user->fresh()->email)->toBe('old@example.test')
        ->and(LoginLink::query()->sole()->used_at)->toBeNull()
        ->and($this->get(route('account.show'))->inertiaPage()['flash']['accountError'])->toBe(__('account.email.taken'));
});

test('a new request invalidates the previous email change link', function () {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);
    $first = requestEmailChange($user, 'first@example.test');
    requestEmailChange($user, 'second@example.test');

    $this->post(route('account.link.consume', $first))->assertRedirect(route('account.link.show', $first));

    expect($user->fresh()->email)->toBe('old@example.test');
});

test('an email change link cannot be used to sign in nor by another user', function () {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);
    $token = requestEmailChange($user, 'new@example.test');
    $intruder = User::factory()->withVerifiedEmail()->create();

    $this->actingAs($intruder)->post(route('account.link.consume', $token))->assertRedirect(route('account.link.show', $token));
    $this->post(route('logout'));
    $this->post(route('login.link.consume', $token))->assertRedirect(route('login.link.show', $token));

    expect($user->fresh()->email)->toBe('old@example.test')
        ->and($intruder->fresh()->email)->not->toBe('new@example.test')
        ->and(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
});

test('the current email and invalid addresses are rejected', function (string $email) {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->postJson(route('account.email.update'), ['email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Mail::assertNothingQueued();
})->with(['old@example.test', 'not-an-email', '']);

test('email change requests use the magic link limits', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post(route('account.email.update'), ['email' => 'new@example.test'])->assertRedirect();
    }

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post(route('account.email.update'), ['email' => 'new@example.test'])->assertStatus(429);
});

test('changing the email without a recent confirmation is sent to confirm', function () {
    $user = User::factory()->withVerifiedEmail()->create(['email' => 'old@example.test']);

    $this->actingAs($user)
        ->post(route('account.email.update'), ['email' => 'new@example.test'])
        ->assertRedirect(route('account.confirm.show'));

    Mail::assertNothingQueued();
});
