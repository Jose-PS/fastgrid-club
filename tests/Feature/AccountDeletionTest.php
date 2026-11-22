<?php

declare(strict_types=1);

use App\Events\AccountDeleted;
use App\Logging\MaskSensitiveDataTap;
use App\Models\LoginLink;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;

beforeEach(function () {
    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
});

test('deleting the account removes the user, identities, passkeys and pending links and signs out', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam()->withGoogle()->create(['email' => 'ana@example.test']);
    $user->passkeys()->create(['name' => 'Portátil', 'credential_id' => Str::random(32), 'credential' => []]);
    LoginLink::factory()->create(['email' => 'ana@example.test']);
    LoginLink::factory()->create(['email' => 'new@example.test', 'user_id' => $user->id]);
    $other = User::factory()->withVerifiedEmail()->withSteam()->create();
    LoginLink::factory()->create(['email' => 'other@example.test']);

    $this->actingAs($user)
        ->delete(route('account.destroy'), ['confirmation' => 'BORRAR'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('en.home'));

    $this->assertGuest();
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(UserIdentity::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Passkey::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(LoginLink::query()->pluck('email')->all())->toBe(['other@example.test'])
        ->and($other->fresh()->hasSteam())->toBeTrue();

    $this->get(route('account.show'))->assertRedirect(route('login'));
});

test('the display name also works as written confirmation', function () {
    $user = User::factory()->withVerifiedEmail()->create(['name' => 'Max Piloto']);

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en')
        ->delete(route('account.destroy'), ['confirmation' => 'Max Piloto'])
        ->assertRedirect(route('en.home'));

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

test('a wrong written confirmation deletes nothing', function (string $confirmation) {
    $user = User::factory()->withVerifiedEmail()->create(['name' => 'Max Piloto']);

    $this->actingAs($user)
        ->deleteJson(route('account.destroy'), ['confirmation' => $confirmation])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmation');

    expect($user->fresh())->not->toBeNull();
    $this->assertAuthenticatedAs($user);
})->with(['', 'borrar', 'Otro nombre']);

test('deleting the account dispatches the account deleted event with the steam id and the hide choice', function (bool $hideLaps) {
    Event::fake([AccountDeleted::class]);
    $user = User::factory()->withVerifiedEmail()->withSteam('76561198000000077')->create();

    $this->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'BORRAR', 'hide_laps' => $hideLaps ? '1' : '0']);

    Event::assertDispatched(AccountDeleted::class, fn (AccountDeleted $event): bool => $event->userId === $user->id
        && $event->steamId === '76561198000000077'
        && $event->hideLaps === $hideLaps);
})->with([true, false]);

test('the account deleted event has no steam id when steam was not linked', function () {
    Event::fake([AccountDeleted::class]);
    $user = User::factory()->withVerifiedEmail()->create();

    $this->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'BORRAR']);

    Event::assertDispatched(AccountDeleted::class, fn (AccountDeleted $event): bool => $event->steamId === null && $event->hideLaps === false);
});

test('the deletion is logged without the email nor the steam id', function () {
    $path = storage_path('logs/account-deletion-test-'.Str::random(8).'.log');
    config([
        'logging.default' => 'deletiontest',
        'logging.channels.deletiontest' => ['driver' => 'single', 'path' => $path, 'tap' => [MaskSensitiveDataTap::class]],
    ]);
    $user = User::factory()->withVerifiedEmail()->withSteam('76561198000000099')->create(['email' => 'secret.pilot@example.test']);

    $this->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'BORRAR'])->assertRedirect(route('en.home'));

    $contents = (string) file_get_contents($path);
    @unlink($path);

    expect($contents)->toContain('account.deleted')
        ->not->toContain('example.test')
        ->not->toContain('secret.pilot')
        ->not->toContain('76561198000000099');
});

test('guests cannot delete accounts', function () {
    $this->deleteJson(route('account.destroy'), ['confirmation' => 'BORRAR'])->assertUnauthorized();
});
