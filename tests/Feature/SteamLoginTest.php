<?php

declare(strict_types=1);

use App\Auth\SteamProvider;
use App\Enums\IdentityProvider;
use App\Logging\MaskSensitiveDataTap;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

const STEAM_ID = '76561198000000001';
const OTHER_STEAM_ID = '76561198000000002';
const STEAM_OPENID = 'https://steamcommunity.com/openid/login';

beforeEach(function () {
    config([
        'services.steam.nonce_cache_store' => 'array',
        'services.steam.client_secret' => null,
    ]);

    Http::fake([
        'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"),
        'api.steampowered.com/*' => Http::response(['response' => ['players' => []]]),
    ]);
});

function steamState(): string
{
    $response = test()->get(route('auth.steam.redirect'));

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
    parse_str((string) parse_url($params['openid_return_to'] ?? $params['openid.return_to'], PHP_URL_QUERY), $returnTo);

    return $returnTo['state'];
}

function steamReturn(string $state, array $overrides = [], string $steamId = STEAM_ID): array
{
    $claimedId = 'https://steamcommunity.com/openid/id/'.$steamId;

    return array_filter([
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'id_res',
        'openid.op_endpoint' => STEAM_OPENID,
        'openid.claimed_id' => $claimedId,
        'openid.identity' => $claimedId,
        'openid.return_to' => route('auth.steam.callback').'?state='.$state,
        'openid.response_nonce' => gmdate('Y-m-d\TH:i:s\Z').Str::random(12),
        'openid.assoc_handle' => '1234567890',
        'openid.signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid.sig' => base64_encode(Str::random(20)),
        ...$overrides,
    ], fn ($value) => $value !== null);
}

function steamCallback(array $params, array $query = [])
{
    $params = array_combine(array_map(fn ($key) => str_replace('.', '_', $key), array_keys($params)), $params);

    return test()->call('GET', route('auth.steam.callback'), $query + $params);
}

function steamLogin(string $steamId = STEAM_ID)
{
    $state = steamState();

    return steamCallback(steamReturn($state, [], $steamId), ['state' => $state]);
}

test('the redirect points to steam with the state in the signed return_to', function () {
    $response = $this->get(route('auth.steam.redirect'));

    $response->assertRedirectContains(STEAM_OPENID.'?');

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);
    parse_str((string) parse_url($params['openid_return_to'] ?? $params['openid.return_to'], PHP_URL_QUERY), $returnTo);

    expect($returnTo['state'])->toBe(session(SteamProvider::StateKey))->toHaveLength(40);
});

test('a valid return creates the user and identity and signs in', function () {
    $oldSessionId = session()->getId();

    steamLogin()->assertRedirect(route('en.home'));

    $identity = UserIdentity::query()->where('provider_id', STEAM_ID)->firstOrFail();

    expect($identity->provider)->toBe(IdentityProvider::Steam)
        ->and($identity->display_name)->toBe('Piloto 0001')
        ->and($identity->avatar_url)->toBeNull()
        ->and($identity->linked_at)->not->toBeNull()
        ->and($identity->user->email)->toBeNull()
        ->and($identity->user->name)->toBe('Piloto 0001')
        ->and(User::count())->toBe(1)
        ->and(session()->getId())->not->toBe($oldSessionId);

    $this->assertAuthenticatedAs($identity->user);
});

test('a second login with the same steam id signs in the same user', function () {
    steamLogin()->assertRedirect();
    $userId = User::firstOrFail()->id;
    $this->post(route('logout'));
    $this->assertGuest();

    steamLogin()->assertRedirect();

    expect(User::count())->toBe(1)
        ->and(UserIdentity::count())->toBe(1);

    $this->assertAuthenticatedAs(User::findOrFail($userId));
});

test('two consecutive returns with different steam ids do not leak into each other', function () {
    steamLogin(STEAM_ID);
    $first = auth()->user();
    $this->post(route('logout'));

    steamLogin(OTHER_STEAM_ID);

    expect(auth()->id())->not->toBe($first->id)
        ->and(auth()->user()->steam_id)->toBe(OTHER_STEAM_ID)
        ->and(UserIdentity::count())->toBe(2);
});

test('the driver is built per request', function () {
    $first = Socialite::driver('steam');
    Socialite::forgetDrivers();

    expect(Socialite::driver('steam'))->not->toBe($first)->toBeInstanceOf(SteamProvider::class);
});

test('invalid returns are rejected without changes', function (string $case) {
    $state = steamState();
    $query = ['state' => $state];
    $overrides = [];
    $steamId = STEAM_ID;

    switch ($case) {
        case 'is_valid false':
            Http::swap(new Factory);
            Http::fake([STEAM_OPENID => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:false\n")]);
            break;
        case 'different return_to':
            $overrides['openid.return_to'] = route('auth.steam.callback').'?state='.$state.'&x=1';
            break;
        case 'return_to of another host':
            $overrides['openid.return_to'] = 'https://evil.test/auth/steam/callback?state='.$state;
            break;
        case 'different op_endpoint':
            $overrides['openid.op_endpoint'] = 'https://evil.test/openid/login';
            break;
        case 'malformed claimed_id':
            $overrides['openid.claimed_id'] = $overrides['openid.identity'] = 'https://steamcommunity.com/openid/id/abc';
            break;
        case 'http claimed_id':
            $overrides['openid.claimed_id'] = $overrides['openid.identity'] = 'http://steamcommunity.com/openid/id/'.STEAM_ID;
            break;
        case 'claimed_id with suffix':
            $overrides['openid.claimed_id'] = $overrides['openid.identity'] = 'https://steamcommunity.com/openid/id/'.STEAM_ID.'9';
            break;
        case 'signed without return_to':
            $overrides['openid.signed'] = 'signed,op_endpoint,claimed_id,identity,response_nonce,assoc_handle';
            break;
        case 'signed without claimed_id':
            $overrides['openid.signed'] = 'signed,op_endpoint,identity,return_to,response_nonce,assoc_handle';
            break;
        case 'signed without response_nonce':
            $overrides['openid.signed'] = 'signed,op_endpoint,claimed_id,identity,return_to,assoc_handle';
            break;
        case 'wrong mode':
            $overrides['openid.mode'] = 'cancel';
            break;
        case 'missing state':
            unset($query['state']);
            $overrides['openid.return_to'] = route('auth.steam.callback');
            break;
        case 'different state':
            $query['state'] = Str::random(40);
            $overrides['openid.return_to'] = route('auth.steam.callback').'?state='.$query['state'];
            break;
    }

    steamCallback(steamReturn($state, $overrides, $steamId), $query)->assertRedirect(route('login'));

    expect(User::count())->toBe(0)->and(UserIdentity::count())->toBe(0);
    $this->assertGuest();
})->with([
    'is_valid false',
    'different return_to',
    'return_to of another host',
    'different op_endpoint',
    'malformed claimed_id',
    'http claimed_id',
    'claimed_id with suffix',
    'signed without return_to',
    'signed without claimed_id',
    'signed without response_nonce',
    'wrong mode',
    'missing state',
    'different state',
]);

test('a state cannot be reused', function () {
    $state = steamState();

    steamCallback(steamReturn($state), ['state' => $state])->assertRedirect(route('en.home'));
    $this->post(route('logout'));

    steamCallback(steamReturn($state), ['state' => $state])->assertRedirect(route('login'));

    expect(User::count())->toBe(1);
    $this->assertGuest();
});

test('a repeated nonce is rejected', function () {
    $nonce = gmdate('Y-m-d\TH:i:s\Z').'replayed';

    $state = steamState();
    steamCallback(steamReturn($state, ['openid.response_nonce' => $nonce]), ['state' => $state])->assertRedirect(route('en.home'));
    $this->post(route('logout'));

    $state = steamState();
    steamCallback(steamReturn($state, ['openid.response_nonce' => $nonce]), ['state' => $state])->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a rejected return flashes a generic error to the login page', function () {
    $state = steamState();

    steamCallback(steamReturn($state, ['openid.mode' => 'cancel']), ['state' => $state])
        ->assertRedirect(route('login'));

    $this->get(route('login'))->assertInertia(fn ($page) => $page->component('auth/login'));
});

test('a user with an email can link steam', function () {
    $user = User::factory()->create(['email' => 'driver@example.test']);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    steamLogin()->assertRedirect(route('account.show'));

    expect($user->fresh()->steam_id)->toBe(STEAM_ID)
        ->and(User::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('linking a steam id owned by another user changes nothing', function () {
    $owner = User::factory()->create();
    $owner->identities()->create(['provider' => IdentityProvider::Steam, 'provider_id' => STEAM_ID, 'display_name' => 'Owner', 'linked_at' => now()]);
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    steamLogin()->assertRedirect(route('account.show'));

    expect($user->fresh()->hasSteam())->toBeFalse()
        ->and($owner->fresh()->steam_id)->toBe(STEAM_ID)
        ->and(UserIdentity::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('a user who already has steam cannot link another', function () {
    $user = User::factory()->create();
    $user->identities()->create(['provider' => IdentityProvider::Steam, 'provider_id' => STEAM_ID, 'display_name' => 'Mine', 'linked_at' => now()]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    steamLogin(OTHER_STEAM_ID)->assertRedirect(route('account.show'));

    expect($user->fresh()->steam_id)->toBe(STEAM_ID)
        ->and(UserIdentity::count())->toBe(1);
});

test('without an api key the name is pilot plus the last four digits', function () {
    steamLogin();

    expect(auth()->user()->name)->toBe('Piloto 0001');
    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'api.steampowered.com'));
});

test('with an api key the persona name and avatar come from the https web api', function () {
    config(['services.steam.client_secret' => 'SECRETKEY']);
    Http::swap(new Factory);
    Http::fake([
        'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"),
        'api.steampowered.com/*' => Http::response(['response' => ['players' => [[
            'steamid' => STEAM_ID,
            'personaname' => 'Max Piloto',
            'avatarmedium' => 'https://avatars.steamstatic.com/a_medium.jpg',
        ]]]]),
    ]);

    steamLogin();

    $identity = UserIdentity::firstOrFail();

    expect(auth()->user()->name)->toBe('Max Piloto')
        ->and($identity->display_name)->toBe('Max Piloto')
        ->and($identity->avatar_url)->toBe('https://avatars.steamstatic.com/a_medium.jpg');

    Http::assertSent(fn (ClientRequest $request) => str_starts_with($request->url(), 'https://api.steampowered.com/')
        && str_contains($request->url(), 'key=SECRETKEY'));
});

test('an empty players list falls back to the pilot name', function () {
    config(['services.steam.client_secret' => 'SECRETKEY']);

    steamLogin();

    expect(auth()->user()->name)->toBe('Piloto 0001');
});

function steamFakePersona(string $personaName): void
{
    config(['services.steam.client_secret' => 'SECRETKEY']);
    Http::swap(new Factory);
    Http::fake([
        'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"),
        'api.steampowered.com/*' => Http::response(['response' => ['players' => [[
            'steamid' => STEAM_ID,
            'personaname' => $personaName,
            'avatarmedium' => 'https://avatars.steamstatic.com/a_medium.jpg',
        ]]]]),
    ]);
}

test('a returning steam user with an automatic pilot name takes the persona name', function () {
    steamLogin();
    $this->post(route('logout'));

    steamFakePersona('Max Piloto');
    steamLogin();

    $identity = UserIdentity::firstOrFail();

    expect(User::count())->toBe(1)
        ->and(User::firstOrFail()->name)->toBe('Max Piloto')
        ->and($identity->display_name)->toBe('Max Piloto');
});

test('a returning steam user with an edited name keeps it but gets the persona display name', function () {
    steamLogin();
    User::query()->update(['name' => 'Mi Alias']);
    $this->post(route('logout'));

    steamFakePersona('Max Piloto');
    steamLogin();

    expect(User::firstOrFail()->name)->toBe('Mi Alias')
        ->and(UserIdentity::firstOrFail()->display_name)->toBe('Max Piloto');
});

test('a returning steam user is not renamed without an api key', function () {
    steamLogin();
    UserIdentity::query()->update(['display_name' => 'Viejo']);
    $this->post(route('logout'));

    steamLogin();

    expect(User::firstOrFail()->name)->toBe('Piloto 0001')
        ->and(UserIdentity::firstOrFail()->display_name)->toBe('Viejo');
});

test('a returning steam user is not renamed when the web api returns no players', function () {
    steamLogin();
    $this->post(route('logout'));

    config(['services.steam.client_secret' => 'SECRETKEY']);
    steamLogin();

    expect(User::firstOrFail()->name)->toBe('Piloto 0001')
        ->and(UserIdentity::firstOrFail()->display_name)->toBe('Piloto 0001');
});

test('the login throttle limits the redirect by ip', function () {
    foreach (range(1, 10) as $attempt) {
        $this->get(route('auth.steam.redirect'))->assertRedirect();
    }

    $this->get(route('auth.steam.redirect'))->assertStatus(429);
});

test('the link throttle limits by user', function () {
    $this->actingAs(User::factory()->create());

    foreach (range(1, 10) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$attempt])
            ->get(route('auth.steam.redirect'))->assertRedirect();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->get(route('auth.steam.redirect'))->assertStatus(429);
});

test('the link limiter does not apply to guests', function () {
    $request = request();

    expect(RateLimiter::limiter('link')($request)->maxAttempts)->toBe(PHP_INT_MAX);
});

test('rejections are logged without the full steam id', function () {
    $path = storage_path('logs/steam-test-'.Str::random(8).'.log');
    config([
        'logging.default' => 'steamtest',
        'logging.channels.steamtest' => ['driver' => 'single', 'path' => $path, 'tap' => [MaskSensitiveDataTap::class]],
    ]);

    $state = steamState();
    steamCallback(steamReturn($state, ['openid.mode' => 'cancel']), ['state' => $state]);

    $contents = (string) file_get_contents($path);
    @unlink($path);

    expect($contents)->toContain('steam.callback.rejected')
        ->not->toContain(STEAM_ID);
});

test('linking steam returns to the account with a visible success message', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    steamLogin()->assertRedirect(route('account.show'));

    $response = $this->get(route('account.show'))->assertInertia(fn ($page) => $page
        ->component('account/show')
        ->where('methods.steam.label', 'Piloto 0001'));

    expect($response->inertiaPage()['flash']['toast']['message'])->toBe(__('account.methods.linked.steam'));
});

test('a failed steam link returns to the account with a visible error', function () {
    $owner = User::factory()->create();
    $owner->identities()->create(['provider' => IdentityProvider::Steam, 'provider_id' => STEAM_ID, 'display_name' => 'Owner', 'linked_at' => now()]);
    $user = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    steamLogin()->assertRedirect(route('account.show'));

    $response = $this->get(route('account.show'))->assertInertia(fn ($page) => $page
        ->component('account/show')
        ->where('methods.steam', null));

    expect($response->inertiaPage()['flash']['steamError'])->toBe(__('login.steamTaken'));
});

test('a rejected steam return while signed in shows the error on the account', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($user);
    $state = steamState();

    steamCallback(steamReturn($state, ['openid.mode' => 'cancel']), ['state' => $state])->assertRedirect(route('account.show'));

    $response = $this->get(route('account.show'))->assertOk();

    expect($response->inertiaPage()['flash']['steamError'])->toBe(__('login.steamError'));
});

test('signing in again with the linked steam confirms the identity', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam(STEAM_ID)->create();
    $this->actingAs($user);

    expect(session('auth.password_confirmed_at'))->toBeNull();

    steamLogin()->assertRedirect(route('account.show'));

    expect(session('auth.password_confirmed_at'))->toBeInt()
        ->and(UserIdentity::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('a guest can still start the steam login', function () {
    $this->get(route('auth.steam.redirect'))->assertRedirect();
});

test('linking steam without a recent confirmation is sent to confirm and links nothing', function () {
    $user = User::factory()->withVerifiedEmail()->create();
    $this->actingAs($user);

    steamLogin()->assertRedirect(route('account.confirm.show'));

    expect($user->fresh()->hasSteam())->toBeFalse();
});

test('the signed in steam link route needs a recent confirmation', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    $this->actingAs($user)->get(route('account.identities.steam.link'))->assertRedirect(route('account.confirm.show'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(route('account.identities.steam.link'))
        ->assertRedirect();
});
