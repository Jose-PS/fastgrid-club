<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('guests and non admins cannot reach the account routes', function () {
    $routes = [
        ['get', route('admin.social.index')],
        ['put', route('admin.social.accounts.update', 'discord')],
        ['post', route('admin.social.accounts.test', 'discord')],
    ];

    foreach ([null, User::factory()->create()] as $user) {
        if ($user !== null) {
            $this->actingAs($user);
        }

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url, ['enabled' => false])->assertNotFound();
        }
    }

    expect(SocialAccount::query()->count())->toBe(0);
});

test('the accounts tab props never contain secrets', function () {
    SocialAccount::factory()->discord()->create();
    SocialAccount::factory()->bluesky()->create();
    SocialAccount::factory()->instagram()->create();

    $response = $this->actingAs($this->admin)->get(route('admin.social.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 3)
            ->where('accounts.0.channel', 'discord')
            ->where('accounts.0.secrets', ['webhook_announcements' => true, 'webhook_results' => true])
            ->where('accounts.1.values', ['identifier' => 'fastgrid.bsky.social'])
            ->where('accounts.1.secrets', ['app_password' => true])
            ->where('accounts.2.values', ['ig_user_id' => '17841400000000000'])
            ->where('accounts.2.secrets', ['access_token' => true]));

    $payload = json_encode($response->viewData('page'));

    expect($payload)->not->toContain('secret-token')
        ->not->toContain('results-token')
        ->not->toContain('app-secret')
        ->not->toContain('ig-secret');
});

test('saving keeps secrets when the input is empty and replaces them when filled', function () {
    SocialAccount::factory()->bluesky()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.social.accounts.update', 'bluesky'), ['enabled' => true, 'identifier' => 'nuevo.bsky.social', 'app_password' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $account = SocialAccount::query()->firstOrFail();

    expect($account->credential('identifier'))->toBe('nuevo.bsky.social')
        ->and($account->credential('app_password'))->toBe('app-secret');

    $this->put(route('admin.social.accounts.update', 'bluesky'), ['enabled' => true, 'identifier' => 'nuevo.bsky.social', 'app_password' => 'otra'])
        ->assertSessionHasNoErrors();

    expect($account->fresh()->credential('app_password'))->toBe('otra');
});

test('a secret is removed only with the remove action', function () {
    SocialAccount::factory()->discord()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.social.accounts.update', 'discord'), ['enabled' => true, 'clear' => ['webhook_results']])
        ->assertSessionHasNoErrors();

    $account = SocialAccount::query()->firstOrFail();

    expect($account->credential('webhook_results'))->toBeNull()
        ->and($account->credential('webhook_announcements'))->toContain('secret-token');
});

test('the account is created on first save and can be paused', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.social.accounts.update', 'discord'), [
            'enabled' => false,
            'webhook_announcements' => 'https://discord.com/api/webhooks/1/abc-DEF_1',
            'announcements_role_id' => '555',
        ])
        ->assertSessionHasNoErrors();

    $account = SocialAccount::query()->sole();

    expect($account->enabled)->toBeFalse()
        ->and($account->credential('webhook_announcements'))->toBe('https://discord.com/api/webhooks/1/abc-DEF_1')
        ->and($account->credential('announcements_role_id'))->toBe('555');
    expect(DB::table('social_accounts')->value('credentials'))->not->toContain('abc-DEF_1');
});

test('the instagram account id must be numeric and is stored in plain', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.social.accounts.update', 'instagram'), ['enabled' => true, 'access_token' => 'tok', 'ig_user_id' => 'abc'])
        ->assertSessionHasErrors('ig_user_id');

    expect(SocialAccount::query()->count())->toBe(0);

    $this->put(route('admin.social.accounts.update', 'instagram'), ['enabled' => true, 'access_token' => 'tok', 'ig_user_id' => '17841400000000000'])
        ->assertSessionHasNoErrors();

    expect(SocialAccount::query()->sole()->credential('ig_user_id'))->toBe('17841400000000000');
});

test('validation rejects webhooks that are not discord urls and bad role ids', function (array $input, string $field) {
    SocialAccount::factory()->discord()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.social.accounts.update', 'discord'), ['enabled' => true, ...$input])
        ->assertSessionHasErrors($field);

    expect(SocialAccount::query()->firstOrFail()->credential('webhook_announcements'))->toContain('secret-token');
})->with([
    'http' => [['webhook_announcements' => 'http://discord.com/api/webhooks/1/abc'], 'webhook_announcements'],
    'other host' => [['webhook_results' => 'https://evil.example/api/webhooks/1/abc'], 'webhook_results'],
    'look alike host' => [['webhook_results' => 'https://discord.com.evil.example/api/webhooks/1/abc'], 'webhook_results'],
    'role id' => [['announcements_role_id' => '12ab'], 'announcements_role_id'],
]);

test('the clear list only accepts secret fields of the channel', function () {
    SocialAccount::factory()->bluesky()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.social.accounts.update', 'bluesky'), ['enabled' => true, 'clear' => ['identifier']])
        ->assertSessionHasErrors('clear.0');
});

test('saving and testing write an audit entry without secret values', function () {
    SocialAccount::factory()->bluesky()->create();
    Http::fake(['bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => 'jwt', 'did' => 'did:plc:abc'])]);

    $this->actingAs($this->admin)
        ->put(route('admin.social.accounts.update', 'bluesky'), ['enabled' => true, 'identifier' => 'fastgrid.bsky.social', 'app_password' => 'brand-new-secret'])
        ->assertSessionHasNoErrors();
    $this->post(route('admin.social.accounts.test', 'bluesky'))->assertRedirect();

    $updated = AuditLog::query()->where('action', 'social_account.updated')->sole();
    $tested = AuditLog::query()->where('action', 'social_account.tested')->sole();

    expect($updated->after)->toBe(['channel' => 'bluesky', 'enabled' => true, 'changed' => ['app_password']])
        ->and($tested->after)->toBe(['channel' => 'bluesky', 'ok' => true])
        ->and(json_encode(AuditLog::query()->get()))->not->toContain('brand-new-secret');
});

test('testing a discord channel calls each configured webhook without posting', function () {
    SocialAccount::factory()->discord()->create();
    Http::fake(['discord.com/*' => Http::response(['id' => '1', 'name' => 'hook'])]);

    $this->actingAs($this->admin)->post(route('admin.social.accounts.test', 'discord'))->assertRedirect();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

test('a failing discord webhook reports the failure without leaking the url', function () {
    SocialAccount::factory()->discord()->create();
    Http::fake(['discord.com/*' => Http::response(['message' => 'Unknown Webhook'], 404)]);

    $this->actingAs($this->admin)->post(route('admin.social.accounts.test', 'discord'))->assertRedirect();

    $toast = $this->get(route('admin.social.index'))->inertiaPage()['flash']['toast'];

    expect(AuditLog::query()->where('action', 'social_account.tested')->sole()->after['ok'])->toBeFalse()
        ->and($toast['type'])->toBe('error')
        ->and($toast['message'])->toContain('webhook_announcements: HTTP 404')
        ->and($toast['message'])->not->toContain('secret-token');
});

test('testing bluesky and instagram uses the stored credentials', function () {
    SocialAccount::factory()->bluesky()->paused()->create();
    SocialAccount::factory()->instagram()->create();
    Http::fake([
        'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired'], 401),
        'graph.facebook.com/*/17841400000000000*' => Http::response(['username' => 'fastgrid']),
    ]);

    $this->actingAs($this->admin);
    $this->post(route('admin.social.accounts.test', 'bluesky'))->assertRedirect();
    $this->post(route('admin.social.accounts.test', 'instagram'))->assertRedirect();

    $results = AuditLog::query()->where('action', 'social_account.tested')->orderBy('id')->get()->pluck('after.ok')->all();

    expect($results)->toBe([false, true]);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'createSession')
        && $request['identifier'] === 'fastgrid.bsky.social' && $request['password'] === 'app-secret');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'graph.facebook.com/v27.0/17841400000000000')
        && $request['fields'] === 'username' && $request['access_token'] === 'ig-secret');
});

test('testing a channel without credentials sends nothing', function () {
    Http::fake();

    $this->actingAs($this->admin)->post(route('admin.social.accounts.test', 'instagram'))->assertRedirect();

    Http::assertNothingSent();
    expect(AuditLog::query()->where('action', 'social_account.tested')->sole()->after['ok'])->toBeFalse();
});

test('testing instagram reports a failure without leaking the token', function () {
    SocialAccount::factory()->instagram()->create();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token ig-secret']], 400)]);

    $this->actingAs($this->admin)->post(route('admin.social.accounts.test', 'instagram'))->assertRedirect();

    $log = AuditLog::query()->where('action', 'social_account.tested')->sole();

    expect($log->after['ok'])->toBeFalse()
        ->and(json_encode($log->after))->not->toContain('ig-secret');
});
