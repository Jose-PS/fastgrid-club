<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Passkey;
use Tests\Feature\Support\VirtualAuthenticator;
use Tests\TestCase;

function passkeyAuthenticator(): VirtualAuthenticator
{
    return new VirtualAuthenticator(
        (string) config('passkeys.relying_party_id'),
        rtrim((string) config('app.url'), '/'),
    );
}

function registerVirtualPasskey(TestCase $test, User $user, VirtualAuthenticator $authenticator, int $signCount = 0): void
{
    $options = $test->actingAs($user)->getJson(route('passkey.registration-options'))->assertOk()->json('options');

    $test->postJson(route('passkey.store'), [
        'name' => 'Portátil',
        'credential' => $authenticator->register($options, $signCount),
    ])->assertOk();

    Auth::guard('web')->logout();
}

test('registration options require user verification and a discoverable credential', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    $options = $this->actingAs($user)->getJson(route('passkey.registration-options'))->assertOk()->json('options');

    expect($options['rp']['id'])->toBe('localhost')
        ->and($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['authenticatorSelection']['residentKey'])->toBe('required')
        ->and($options['timeout'])->toBe(300000)
        ->and(session('passkey.registration_options'))->toBeString()
        ->and(session('passkey.registration_options_issued_at'))->toBeInt();
});

test('registration options name the passkey after the email, or after the name when there is no email', function () {
    $withEmail = User::factory()->create(['email' => 'piloto@example.test']);
    $withoutEmail = User::factory()->withoutEmail()->create(['name' => 'Piloto Steam']);

    $this->actingAs($withEmail)->getJson(route('passkey.registration-options'))
        ->assertOk()
        ->assertJsonPath('options.user.name', 'piloto@example.test');

    $this->actingAs($withoutEmail)->getJson(route('passkey.registration-options'))
        ->assertOk()
        ->assertJsonPath('options.user.name', 'Piloto Steam');
});

test('login options require user verification and allow discoverable credentials', function () {
    $options = $this->getJson(route('passkey.login-options'))->assertOk()->json('options');

    expect($options['rpId'])->toBe('localhost')
        ->and($options['userVerification'])->toBe('required')
        ->and($options['allowCredentials'] ?? [])->toBe([])
        ->and(session('passkey.verification_options'))->toBeString()
        ->and(session('passkey.verification_options_issued_at'))->toBeInt();
});

test('the relying party and origin come from the app url', function () {
    expect(config('passkeys.relying_party_id'))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST))
        ->and(config('passkeys.allowed_origins'))->toBe([config('app.url')]);
});

test('a user registers a passkey and signs in with it without a session', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();

    registerVirtualPasskey($this, $user, $authenticator, signCount: 5);

    $passkey = $user->passkeys()->sole();
    expect($passkey->name)->toBe('Portátil')
        ->and($passkey->credential['counter'])->toBe(5);

    $this->get(route('login', ['redirect' => '/calendar']))->assertOk();
    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $sessionId = session()->getId();

    $this->postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, signCount: 6)])
        ->assertOk()
        ->assertJson(['redirect' => url('/calendar')]);

    $this->assertAuthenticatedAs($user);
    $passkey->refresh();
    expect(session()->getId())->not->toBe($sessionId)
        ->and($user->fresh()->remember_token)->not->toBeNull()
        ->and($passkey->credential['counter'])->toBe(6)
        ->and($passkey->last_used_at)->not->toBeNull();
});

test('a signature counter that goes backwards is rejected', function (int $signCount) {
    $user = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();
    registerVirtualPasskey($this, $user, $authenticator, signCount: 5);

    $options = $this->getJson(route('passkey.login-options'))->json('options');

    $this->postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, $signCount)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential' => __('login.passkeyError')]);

    $this->assertGuest();
    expect($user->passkeys()->sole()->credential['counter'])->toBe(5);
})->with([3, 5]);

test('an assertion from another origin is rejected', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();
    registerVirtualPasskey($this, $user, $authenticator, signCount: 1);

    $options = $this->getJson(route('passkey.login-options'))->json('options');

    $this->postJson(route('passkey.login'), [
        'credential' => $authenticator->assert($options, signCount: 2, origin: 'https://evil.test'),
    ])->assertUnprocessable();

    $this->assertGuest();
});

test('an assertion without user verification is rejected', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();
    registerVirtualPasskey($this, $user, $authenticator, signCount: 1);

    $options = $this->getJson(route('passkey.login-options'))->json('options');

    $this->postJson(route('passkey.login'), [
        'credential' => $authenticator->assert($options, signCount: 2, userVerified: false),
    ])->assertUnprocessable();

    $this->assertGuest();
});

test('a registration from another origin is rejected', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $options = $this->actingAs($user)->getJson(route('passkey.registration-options'))->json('options');

    $this->postJson(route('passkey.store'), [
        'name' => 'Portátil',
        'credential' => passkeyAuthenticator()->register($options, origin: 'https://evil.test'),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['credential' => __('account.passkeys.addError')]);

    expect(Passkey::query()->count())->toBe(0);
});

test('a registration without user verification is rejected', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $options = $this->actingAs($user)->getJson(route('passkey.registration-options'))->json('options');

    $this->postJson(route('passkey.store'), [
        'name' => 'Portátil',
        'credential' => passkeyAuthenticator()->register($options, userVerified: false),
    ])->assertUnprocessable();

    expect(Passkey::query()->count())->toBe(0);
});

test('a registration alias must be between 1 and 40 characters', function (string $name) {
    $user = User::factory()->withVerifiedEmail()->create();
    $options = $this->actingAs($user)->getJson(route('passkey.registration-options'))->json('options');

    $this->postJson(route('passkey.store'), [
        'name' => $name,
        'credential' => passkeyAuthenticator()->register($options),
    ])->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(Passkey::query()->count())->toBe(0);
})->with(['', str_repeat('a', 41)]);

test('a login challenge can only be used once', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();
    registerVirtualPasskey($this, $user, $authenticator, signCount: 1);

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $this->postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, signCount: 2)])->assertOk();
    Auth::guard('web')->logout();

    $this->postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, signCount: 3)])
        ->assertUnprocessable();

    $this->assertGuest();
});

test('a login challenge expires after five minutes', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();
    registerVirtualPasskey($this, $user, $authenticator, signCount: 1);

    $options = $this->getJson(route('passkey.login-options'))->json('options');

    $this->travel(301)->seconds();

    $this->postJson(route('passkey.login'), ['credential' => $authenticator->assert($options, signCount: 2)])
        ->assertUnprocessable();

    $this->assertGuest();
});

test('a registration challenge expires after five minutes', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $options = $this->actingAs($user)->getJson(route('passkey.registration-options'))->json('options');

    $this->travel(301)->seconds();

    $this->postJson(route('passkey.store'), [
        'name' => 'Portátil',
        'credential' => passkeyAuthenticator()->register($options),
    ])->assertUnprocessable();

    expect(Passkey::query()->count())->toBe(0);
});

test('an unknown credential is rejected with a translated message', function () {
    $options = $this->getJson(route('passkey.login-options'))->json('options');

    $this->withHeader('Accept-Language', 'en')
        ->postJson(route('passkey.login'), ['credential' => passkeyAuthenticator()->assert($options, signCount: 1)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential' => 'We could not sign you in with your passkey. Try again or use another method.']);

    $this->assertGuest();
});

test('passkey endpoints are limited to 10 requests per minute per ip', function () {
    foreach (range(1, 10) as $attempt) {
        $this->getJson(route('passkey.login-options'))->assertOk();
    }

    $this->getJson(route('passkey.login-options'))->assertTooManyRequests();
    $this->postJson(route('passkey.login'), ['credential' => []])->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->getJson(route('passkey.login-options'))
        ->assertOk();
});

test('a signed in user confirms with a passkey and can then delete a passkey', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();
    registerVirtualPasskey($this, $user, $authenticator, signCount: 1);
    $passkey = $user->passkeys()->sole();
    session()->forget('auth.password_confirmed_at');

    $this->actingAs($user)->delete(route('passkey.destroy', $passkey))->assertRedirect(route('account.confirm.show'));

    $options = $this->getJson(route('passkey.confirm-options'))->assertOk()->json('options');

    $this->postJson(route('passkey.confirm'), ['credential' => $authenticator->assert($options, signCount: 2)])
        ->assertOk()
        ->assertExactJson(['redirect' => route('account.show')]);

    expect(session('auth.password_confirmed_at'))->toBeInt();

    $this->from(route('account.show'))->delete(route('passkey.destroy', $passkey))->assertRedirect(route('account.show'));

    expect($passkey->fresh())->toBeNull();
});

test('a passkey confirmation with a passkey of another user fails with a translated message', function () {
    $owner = User::factory()->withVerifiedEmail()->create();
    $authenticator = passkeyAuthenticator();
    registerVirtualPasskey($this, $owner, $authenticator, signCount: 1);
    $intruder = User::factory()->withVerifiedEmail()->create();

    $options = $this->actingAs($intruder)->getJson(route('passkey.confirm-options'))->assertOk()->json('options');

    $this->withHeader('Accept-Language', 'en')
        ->postJson(route('passkey.confirm'), ['credential' => $authenticator->assert($options, signCount: 2)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential' => __('account.confirm.passkeyError', [], 'en')]);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});
