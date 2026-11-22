<?php

declare(strict_types=1);

use App\Enums\IdentityProvider;
use App\Logging\MaskSensitiveDataTap;
use App\Models\User;
use App\Models\UserIdentity;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const GOOGLE_SUB = '110000000000000000001';
const OTHER_GOOGLE_SUB = '110000000000000000002';
const GOOGLE_EMAIL = 'Piloto.Real@Example.test';

beforeEach(function () {
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
    ]);
});

function googleProfile(array $overrides = []): array
{
    return [
        'sub' => GOOGLE_SUB,
        'name' => 'Max Piloto',
        'email' => GOOGLE_EMAIL,
        'email_verified' => true,
        'picture' => 'https://lh3.googleusercontent.com/a/photo',
        ...$overrides,
    ];
}

function googleFakeResponses(array $profile): void
{
    config(['services.google.guzzle' => ['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'opaque-token', 'expires_in' => 3600])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode($profile)),
    ]))]]);
}

function googleState(): string
{
    $response = test()->get(route('auth.google.redirect'));

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

    return $params['state'];
}

function googleLogin(array $profile = []): TestResponse
{
    $state = googleState();
    googleFakeResponses(googleProfile($profile));

    return test()->get(route('auth.google.callback', ['state' => $state, 'code' => 'auth-code']));
}

test('the redirect goes to google with pkce, state and the three scopes', function () {
    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirectContains('https://accounts.google.com/');

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

    expect($params['code_challenge'])->not->toBeEmpty()
        ->and($params['code_challenge_method'])->toBe('S256')
        ->and($params['state'])->toBe(session('state'))
        ->and($params['scope'])->toBe('openid profile email')
        ->and($params['redirect_uri'])->toBe(route('auth.google.callback'))
        ->and($params['response_type'])->toBe('code');
});

test('the redirect works with empty credentials', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get(route('auth.google.redirect'))->assertRedirectContains('https://accounts.google.com/');
});

test('a new user with a verified email is created with the google name and identity and signs in', function () {
    $oldSessionId = session()->getId();

    googleLogin()->assertRedirect(route('en.home'));

    $identity = UserIdentity::query()->where('provider_id', GOOGLE_SUB)->firstOrFail();

    expect($identity->provider)->toBe(IdentityProvider::Google)
        ->and($identity->display_name)->toBe('Max Piloto')
        ->and($identity->avatar_url)->toBe('https://lh3.googleusercontent.com/a/photo')
        ->and($identity->user->name)->toBe('Max Piloto')
        ->and($identity->user->email)->toBe('piloto.real@example.test')
        ->and($identity->user->email_verified_at)->not->toBeNull()
        ->and(User::count())->toBe(1)
        ->and(session()->getId())->not->toBe($oldSessionId);

    $this->assertAuthenticatedAs($identity->user);
});

test('the google name is trimmed to 32 characters and short names get a pilot name', function () {
    googleLogin(['name' => str_repeat('a', 40)]);

    expect(auth()->user()->name)->toBe(str_repeat('a', 32));

    $this->post(route('logout'));

    googleLogin(['sub' => OTHER_GOOGLE_SUB, 'name' => 'ab', 'email' => 'other@example.test']);

    expect(auth()->user()->name)->toMatch('/^Piloto \d{4}$/');
});

test('a second login with the same sub signs in the same user and refreshes the profile', function () {
    googleLogin()->assertRedirect();
    $userId = User::firstOrFail()->id;
    $this->post(route('logout'));
    $this->assertGuest();

    googleLogin(['name' => 'Nuevo Nombre', 'picture' => 'http://insecure.test/a.png'])->assertRedirect();

    $identity = UserIdentity::firstOrFail();

    expect(User::count())->toBe(1)
        ->and(UserIdentity::count())->toBe(1)
        ->and($identity->display_name)->toBe('Nuevo Nombre')
        ->and($identity->avatar_url)->toBeNull()
        ->and(User::firstOrFail()->name)->toBe('Max Piloto');
    $this->assertAuthenticatedAs(User::findOrFail($userId));
});

test('an unverified email is rejected without creating anything', function (mixed $verified) {
    googleLogin(['email_verified' => $verified])->assertRedirect(route('login'));

    expect(User::count())->toBe(0)->and(UserIdentity::count())->toBe(0);
    $this->assertGuest();
})->with([false, 'true', null]);

test('an email already used by another account is not linked and shows the explanatory message', function () {
    User::factory()->create(['email' => 'piloto.real@example.test']);

    googleLogin()->assertRedirect(route('login'));

    expect(User::count())->toBe(1)->and(UserIdentity::count())->toBe(0);
    $this->assertGuest();

    expect($this->get(route('login'))->inertiaPage()['flash']['googleError'])->toBe(__('login.googleEmailInUse'));
});

test('an already linked sub signs in even if the email now belongs to another account', function () {
    $user = User::factory()->withGoogle()->create();
    $user->identities()->update(['provider_id' => GOOGLE_SUB]);
    User::factory()->create(['email' => 'piloto.real@example.test']);

    googleLogin()->assertRedirect(route('en.home'));

    $this->assertAuthenticatedAs($user);
});

test('an invalid state is rejected', function () {
    googleState();
    googleFakeResponses(googleProfile());

    $this->get(route('auth.google.callback', ['state' => Str::random(40), 'code' => 'auth-code']))
        ->assertRedirect(route('login'));

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

test('a missing state is rejected', function () {
    googleFakeResponses(googleProfile());

    $this->get(route('auth.google.callback', ['code' => 'auth-code']))->assertRedirect(route('login'));

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

test('a denied or failed token exchange is rejected with the generic error', function () {
    $state = googleState();
    config(['services.google.guzzle' => ['handler' => HandlerStack::create(new MockHandler([
        new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant'])),
    ]))]]);

    $this->get(route('auth.google.callback', ['state' => $state, 'error' => 'access_denied']))
        ->assertRedirect(route('login'));

    expect(User::count())->toBe(0);
    $this->assertGuest();
    expect($this->get(route('login'))->inertiaPage()['flash']['googleError'])->toBe(__('login.googleError'));
});

test('a signed in user can link google even if the google email differs', function () {
    $user = User::factory()->create(['email' => 'driver@example.test']);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    googleLogin()->assertRedirect(route('account.show'));

    $identity = $user->identities()->firstOrFail();

    expect($identity->provider)->toBe(IdentityProvider::Google)
        ->and($identity->provider_id)->toBe(GOOGLE_SUB)
        ->and(User::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('linking a sub owned by another user changes nothing', function () {
    $owner = User::factory()->create();
    $owner->identities()->create(['provider' => IdentityProvider::Google, 'provider_id' => GOOGLE_SUB, 'display_name' => 'Owner', 'linked_at' => now()]);
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    googleLogin()->assertRedirect(route('account.show'));

    expect($user->identities()->count())->toBe(0)
        ->and($owner->identities()->count())->toBe(1)
        ->and(UserIdentity::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('a user who already has google cannot link another', function () {
    $user = User::factory()->create();
    $user->identities()->create(['provider' => IdentityProvider::Google, 'provider_id' => GOOGLE_SUB, 'display_name' => 'Mine', 'linked_at' => now()]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    googleLogin(['sub' => OTHER_GOOGLE_SUB])->assertRedirect(route('account.show'));

    expect($user->identities()->count())->toBe(1)
        ->and($user->identities()->firstOrFail()->provider_id)->toBe(GOOGLE_SUB);
});

test('the login throttle limits the redirect by ip', function () {
    foreach (range(1, 10) as $attempt) {
        $this->get(route('auth.google.redirect'))->assertRedirect();
    }

    $this->get(route('auth.google.redirect'))->assertStatus(429);
});

test('rejections are logged without the email', function () {
    $path = storage_path('logs/google-test-'.Str::random(8).'.log');
    config([
        'logging.default' => 'googletest',
        'logging.channels.googletest' => ['driver' => 'single', 'path' => $path, 'tap' => [MaskSensitiveDataTap::class]],
    ]);

    User::factory()->create(['email' => 'piloto.real@example.test']);
    googleLogin()->assertRedirect(route('login'));
    $this->post(route('logout'));
    googleLogin(['email_verified' => false]);
    $this->get(route('auth.google.callback', ['state' => 'bad']));

    $contents = (string) file_get_contents($path);
    @unlink($path);

    expect($contents)->toContain('google.callback.rejected')
        ->not->toContain('example.test')
        ->not->toContain('opaque-token');
});

test('linking google returns to the account with the google email and a success message', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    googleLogin()->assertRedirect(route('account.show'));

    $response = $this->get(route('account.show'))->assertInertia(fn ($page) => $page
        ->component('account/show')
        ->where('methods.google.label', 'piloto.real@example.test'));

    expect($response->inertiaPage()['flash']['toast']['message'])->toBe(__('account.methods.linked.google'));
});

test('a failed google link returns to the account with a visible error', function () {
    $owner = User::factory()->create();
    $owner->identities()->create(['provider' => IdentityProvider::Google, 'provider_id' => GOOGLE_SUB, 'display_name' => 'Owner', 'linked_at' => now()]);
    $user = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    googleLogin()->assertRedirect(route('account.show'));

    $response = $this->get(route('account.show'))->assertInertia(fn ($page) => $page
        ->component('account/show')
        ->where('methods.google', null));

    expect($response->inertiaPage()['flash']['googleError'])->toBe(__('login.googleTaken'));
});

test('signing in again with the linked google confirms the identity', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $user->identities()->create(['provider' => IdentityProvider::Google, 'provider_id' => GOOGLE_SUB, 'display_name' => 'Mine', 'linked_at' => now()]);
    $this->actingAs($user);

    googleLogin()->assertRedirect(route('account.show'));

    expect(session('auth.password_confirmed_at'))->toBeInt()
        ->and(UserIdentity::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('a guest can still start the google login', function () {
    $this->get(route('auth.google.redirect'))->assertRedirectContains('https://accounts.google.com/');
});

test('linking google without a recent confirmation is sent to confirm and links nothing', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($user);

    googleLogin()->assertRedirect(route('account.confirm.show'));

    expect($user->identities()->count())->toBe(0);
});

test('the signed in google link route needs a recent confirmation', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    $this->actingAs($user)->get(route('account.identities.google.link'))->assertRedirect(route('account.confirm.show'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(route('account.identities.google.link'))
        ->assertRedirectContains('https://accounts.google.com/');
});
