<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Integration\Server\Log\SessionType;
use App\Models\Car;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\Week;
use App\Support\ServerSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('status');
});

test('the endpoint answers with the status, the track and the session', function () {
    $now = CarbonImmutable::parse('2026-11-07 18:30:00', 'Europe/Madrid');
    $this->travelTo($now);
    $server = Server::factory()->create(['is_active' => true, 'max_players' => 24]);
    $track = Track::factory()->create(['display' => 'Imola GP', 'max_pit_slot' => 20]);
    $week = Week::factory()->for($server)->status(WeekStatus::Active)->create(['track_id' => $track->id, 'max_players' => 20]);
    $week->cars()->attach(Car::factory()->count(2)->create()->pluck('id'));
    app(ServerSnapshot::class)->store($server, true, 12, true, $now->subSeconds(3));
    ServerSession::factory()->create(['server_id' => $server->id, 'type' => SessionType::Race, 'started_at' => $now->subMinutes(5), 'ended_at' => null]);

    $this->getJson('/api/estado')
        ->assertOk()
        ->assertExactJson([
            'status' => 'online',
            'players' => 12,
            'maxPlayers' => 20,
            'track' => 'Imola GP',
            'session' => 'race',
            'ingameName' => 'FastGrid | GT3 @ Imola GP | Race Fair, Get Faster',
            'serverNow' => '2026-11-07T18:30:00+01:00',
            'fetchedAt' => '2026-11-07T18:29:57+01:00',
        ]);
});

test('the endpoint is available by name and sends a public cache header', function () {
    expect(route('api.status', absolute: false))->toBe('/api/estado');

    $cache = (string) $this->getJson('/api/estado')->assertOk()->headers->get('Cache-Control');

    expect($cache)->toContain('public')->toContain('max-age=5');
});

test('the endpoint does not start a session or set cookies', function () {
    $this->getJson('/api/estado')->assertOk()->assertCookieMissing(config('session.cookie'));
});

test('without a server or snapshot the endpoint reports unavailable', function () {
    $this->getJson('/api/estado')
        ->assertOk()
        ->assertJson(['status' => 'unavailable', 'players' => null, 'maxPlayers' => null, 'track' => null, 'fetchedAt' => null]);
});

test('the endpoint never reaches out to other services', function () {
    Http::preventStrayRequests();
    Http::fake();
    $server = Server::factory()->create(['is_active' => true]);
    app(ServerSnapshot::class)->store($server, true, 1, true, CarbonImmutable::now());

    $this->getJson('/api/estado')->assertOk();

    Http::assertNothingSent();
});

test('the 61st request in a minute is rate limited', function () {
    foreach (range(1, 60) as $attempt) {
        $this->getJson('/api/estado')->assertOk();
    }

    $this->getJson('/api/estado')->assertStatus(429);
});

test('the status limiter is registered with its configured value', function () {
    expect(config('mvp.rate_limits.status.per_minute'))->toBe(60);
});

test('the snapshot lives under one key per server', function () {
    $server = Server::factory()->create();

    expect(app(ServerSnapshot::class)->key($server))->toBe("server:{$server->id}:snapshot");
});

test('a malformed snapshot is treated as missing', function () {
    $server = Server::factory()->create();
    cache()->forever(app(ServerSnapshot::class)->key($server), ['running' => true]);

    expect(app(ServerSnapshot::class)->read($server))->toBeNull();
});
