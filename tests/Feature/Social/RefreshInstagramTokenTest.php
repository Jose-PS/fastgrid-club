<?php

declare(strict_types=1);

use App\Mail\InstagramTokenRefreshFailedMail;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function instagramAccount(array $attributes = []): SocialAccount
{
    return SocialAccount::factory()->instagram()->create([
        'credentials' => ['access_token' => 'old'],
        'expires_at' => now()->addDays(10),
        ...$attributes,
    ]);
}

test('it does nothing without a token', function () {
    Http::fake();

    $this->artisan('social:refresh-instagram-token')->assertSuccessful();

    SocialAccount::factory()->instagram()->create(['credentials' => []]);

    $this->artisan('social:refresh-instagram-token')->assertSuccessful();

    Http::assertNothingSent();
});

test('it renews a token that expires in less than 30 days', function () {
    instagramAccount();
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'new', 'token_type' => 'bearer', 'expires_in' => 5184000])]);

    $this->artisan('social:refresh-instagram-token')->assertSuccessful();

    $account = SocialAccount::query()->firstOrFail();

    expect($account->credential('access_token'))->toBe('new')
        ->and($account->expires_at->isAfter(now()->addDays(59)))->toBeTrue()
        ->and($account->refreshed_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => $request['grant_type'] === 'ig_refresh_token' && $request['access_token'] === 'old');
});

test('it does not renew a token that expires in more than 30 days', function () {
    instagramAccount(['expires_at' => now()->addDays(45)]);
    Http::fake();

    $this->artisan('social:refresh-instagram-token')->assertSuccessful();

    Http::assertNothingSent();
    expect(SocialAccount::query()->firstOrFail()->credential('access_token'))->toBe('old');
});

test('a token without a known expiry is renewed and the expiry is stored', function () {
    instagramAccount(['expires_at' => null]);
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'new', 'expires_in' => 5184000])]);

    $this->artisan('social:refresh-instagram-token')->assertSuccessful();

    $account = SocialAccount::query()->firstOrFail();

    expect($account->credential('access_token'))->toBe('new')
        ->and($account->expires_at)->not->toBeNull();
});

test('a paused account is still renewed so the token does not expire', function () {
    instagramAccount(['enabled' => false]);
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'new', 'expires_in' => 5184000])]);

    $this->artisan('social:refresh-instagram-token')->assertSuccessful();

    $account = SocialAccount::query()->firstOrFail();

    expect($account->credential('access_token'))->toBe('new')
        ->and($account->enabled)->toBeFalse();
});

test('a failed renewal keeps the token and mails the admins', function () {
    instagramAccount(['expires_at' => now()->addDays(5)]);
    $admin = User::factory()->admin()->create();
    User::factory()->create();
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['error' => 'bad'], 400)]);

    $this->artisan('social:refresh-instagram-token')->assertFailed();

    expect(SocialAccount::query()->firstOrFail()->credential('access_token'))->toBe('old');
    Mail::assertQueuedCount(1);
    Mail::assertQueued(InstagramTokenRefreshFailedMail::class, fn ($mail): bool => $mail->hasTo($admin->email));
});
