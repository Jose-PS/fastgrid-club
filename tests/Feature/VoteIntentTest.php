<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Models\LoginLink;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withHeader('Accept-Language', 'es');
    config([
        'services.steam.nonce_cache_store' => 'array',
        'services.steam.client_secret' => null,
    ]);

    Http::fake([
        'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"),
        'api.steampowered.com/*' => Http::response(['response' => ['players' => []]]),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Europe/Madrid'));

    $this->week = Week::factory()
        ->startingAt(CarbonImmutable::parse('2026-11-16 00:00:00', 'Europe/Madrid'))
        ->create();
    $this->proposal = Proposal::factory()->create(['week_id' => $this->week->id]);
});

function intentLogin(User $user): TestResponse
{
    $token = Str::random(64);

    LoginLink::factory()->create(['email' => $user->email, 'token_hash' => hash('sha256', $token)]);

    return test()->post(route('login.link.consume', $token));
}

function intentSteamLink(string $steamId): TestResponse
{
    $redirect = test()->get(route('auth.steam.redirect'));
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $params);
    parse_str((string) parse_url($params['openid_return_to'] ?? $params['openid.return_to'], PHP_URL_QUERY), $returnTo);

    $state = $returnTo['state'];
    $claimedId = 'https://steamcommunity.com/openid/id/'.$steamId;

    return test()->call('GET', route('auth.steam.callback'), [
        'state' => $state,
        'openid_ns' => 'http://specs.openid.net/auth/2.0',
        'openid_mode' => 'id_res',
        'openid_op_endpoint' => 'https://steamcommunity.com/openid/login',
        'openid_claimed_id' => $claimedId,
        'openid_identity' => $claimedId,
        'openid_return_to' => route('auth.steam.callback').'?state='.$state,
        'openid_response_nonce' => gmdate('Y-m-d\TH:i:s\Z').Str::random(12),
        'openid_assoc_handle' => '1234567890',
        'openid_signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid_sig' => base64_encode(Str::random(20)),
    ], server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
}

function intentGoogleCallback(string $sub, string $email): TestResponse
{
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
    ]);

    $redirect = test()->get(route('auth.google.redirect'));
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $params);

    config(['services.google.guzzle' => ['handler' => HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'opaque-token', 'expires_in' => 3600])),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['sub' => $sub, 'name' => 'Max Piloto', 'email' => $email, 'email_verified' => true])),
    ]))]]);

    return test()->get(route('auth.google.callback', ['state' => $params['state'], 'code' => 'auth-code']));
}

test('a guest intent is stored and sends them to the login', function () {
    $this->post(route('proposals.intent', $this->proposal))->assertRedirect(route('login'));

    expect(session('proposals.vote_intent.proposal_id'))->toBe($this->proposal->id)
        ->and(session('proposals.vote_intent.expires_at'))->toBe(now()->addMinutes(10)->getTimestamp())
        ->and(session('url.intended'))->toBe('/proposals');
});

test('the intent is executed once after signing in with steam linked', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam('76561198000000600')->create();

    $this->post(route('proposals.intent', $this->proposal));

    intentLogin($user)->assertRedirect(url('/proposals'));

    expect(Vote::query()->sole()->proposal_id)->toBe($this->proposal->id)
        ->and(Vote::query()->sole()->steam_id)->toBe('76561198000000600')
        ->and(session()->has('proposals.vote_intent'))->toBeFalse()
        ->and($this->get(route('proposals'))->inertiaPage()['flash']['toast']['message'])->toBe(__('props.voted'));
});

test('without steam the intent is kept until steam is linked', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    $this->post(route('proposals.intent', $this->proposal));
    intentLogin($user);

    expect(Vote::query()->count())->toBe(0)
        ->and(session('proposals.vote_intent.proposal_id'))->toBe($this->proposal->id);

    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    intentSteamLink('76561198000000601')->assertRedirect(route('proposals'));

    expect(Vote::query()->sole()->steam_id)->toBe('76561198000000601')
        ->and(session()->has('proposals.vote_intent'))->toBeFalse();
});

test('without an identity the intent is kept until google is linked', function () {
    $user = User::factory()->withVerifiedEmail()->create();

    $this->post(route('proposals.intent', $this->proposal));
    intentLogin($user);

    expect(Vote::query()->count())->toBe(0);

    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

    intentGoogleCallback('110000000000000000601', 'otra@example.test')->assertRedirect(route('proposals'));

    $vote = Vote::query()->sole();

    expect($vote->user_id)->toBe($user->id)
        ->and($vote->steam_id)->toBeNull()
        ->and(session()->has('proposals.vote_intent'))->toBeFalse();
});

test('the intent is executed after signing in with google', function () {
    $this->post(route('proposals.intent', $this->proposal));

    intentGoogleCallback('110000000000000000602', 'nuevo@example.test')->assertRedirect(url('/proposals'));

    expect(Vote::query()->sole()->proposal_id)->toBe($this->proposal->id)
        ->and(Vote::query()->sole()->user_id)->toBe(auth()->id())
        ->and(session()->has('proposals.vote_intent'))->toBeFalse();
});

test('an expired intent is discarded', function () {
    $user = User::factory()->withVerifiedEmail()->withSteam()->create();

    $this->post(route('proposals.intent', $this->proposal));
    $this->travel(10)->minutes();

    intentLogin($user);

    expect(Vote::query()->count())->toBe(0)
        ->and(session()->has('proposals.vote_intent'))->toBeFalse();
});

test('an intent is not executed when the proposal is hidden, gone or the voting closed', function (string $case) {
    $user = User::factory()->withVerifiedEmail()->withSteam()->create();

    $this->post(route('proposals.intent', $this->proposal));

    match ($case) {
        'hidden' => $this->proposal->update(['hidden_at' => now()]),
        'gone' => $this->proposal->delete(),
        'closed' => $this->week->update(['status' => WeekStatus::Scheduled]),
    };

    intentLogin($user);

    expect(Vote::query()->count())->toBe(0)
        ->and(session()->has('proposals.vote_intent'))->toBeFalse();
})->with(['hidden', 'gone', 'closed']);
