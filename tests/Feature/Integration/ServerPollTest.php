<?php

declare(strict_types=1);

use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Integration\RecordIntegrationSuccess;
use App\Actions\Servers\PollServer;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\IntegrationEventType;
use App\Enums\ServerStatus;
use App\Integration\Server\ServerCircuitBreaker;
use App\Mail\IntegrationFailingMail;
use App\Models\Alert;
use App\Models\IntegrationEvent;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ZinoPanelHelpers.php';

function failingPanel(bool &$down = true): void
{
    zinoFake(['GET /api/server/status' => function () use (&$down) {
        return $down ? Http::response(['error' => 'down'], 500) : Http::response(zinoFixture('status'));
    }]);
}

function fakeServer(): Server
{
    return Server::factory()->create(['is_active' => true]);
}

function pollFailures(Server $server, int $times): void
{
    foreach (range(1, $times) as $_) {
        app(PollServer::class)->handle($server);
    }
}

test('a poll stores the snapshot with state and drivers and marks the server as seen', function () {
    $server = fakeServer();

    $this->artisan('servers:poll')->assertSuccessful();

    $snapshot = app(ServerSnapshot::class)->read($server);

    expect($snapshot['running'])->toBeTrue()
        ->and($snapshot['players'])->toBe(2)
        ->and($snapshot['reachable'])->toBeTrue()
        ->and($snapshot['state'])->toBe('running')
        ->and($snapshot['drivers'])->toHaveCount(2)
        ->and($snapshot['drivers'][0])->toBe(['name' => 'Piloto Uno', 'car' => '1', 'number' => 11, 'laps' => 5, 'last_lap_ms' => 92500, 'best_lap_ms' => 91800])
        ->and($server->fresh()->last_seen_at)->not->toBeNull()
        ->and(Cache::get(PollServer::lastSuccessKey($server)))->not->toBeNull()
        ->and(Cache::get(PollServer::latencyKey($server)))->toBeInt();
});

test('the snapshot expires after 30 seconds', function () {
    $server = fakeServer();

    app(PollServer::class)->handle($server);
    expect(app(ServerSnapshot::class)->read($server))->not->toBeNull();

    $this->travel(31)->seconds();

    expect(app(ServerSnapshot::class)->read($server))->toBeNull();
});

test('inactive servers are not polled', function () {
    $server = Server::factory()->create(['is_active' => false]);

    $this->artisan('servers:poll')->assertSuccessful();

    expect(app(ServerSnapshot::class)->read($server))->toBeNull();
});

test('a failing panel stores an unreachable snapshot and does not stop the command', function () {
    failingPanel();
    $server = zinoServer();
    $server->update(['is_active' => true]);
    $other = fakeServer();

    $this->artisan('servers:poll')->assertSuccessful();

    $snapshot = app(ServerSnapshot::class)->read($server);

    expect($snapshot['reachable'])->toBeFalse()
        ->and($snapshot['running'])->toBeFalse()
        ->and($snapshot['players'])->toBe(0)
        ->and(app(ServerSnapshot::class)->statusAt($snapshot, CarbonImmutable::now()))->toBe(ServerStatus::Unavailable)
        ->and(app(ServerSnapshot::class)->read($other)['reachable'])->toBeTrue();
});

test('a failure creates an integration event without secrets', function () {
    failingPanel();
    $server = zinoServer();

    app(PollServer::class)->handle($server);

    $event = IntegrationEvent::query()->sole();

    expect($event->server_id)->toBe($server->id)
        ->and($event->type)->toBe(IntegrationEventType::PollFailed)
        ->and($event->summary)->toContain('HTTP 500')
        ->and($event->summary)->not->toContain(ZINO_PASS)
        ->and($event->summary)->not->toContain(ZINO_USER)
        ->and($event->created_at)->not->toBeNull();
});

test('an unexpected exception never leaks its message', function () {
    $server = Server::factory()->create(['driver' => 'zino_panel', 'credentials_ref' => 'missing']);
    config(['servers.missing' => 'secret-in-the-message']);
    Http::fake(fn () => throw new RuntimeException('password=hunter2'));

    app(PollServer::class)->handle($server);

    $event = IntegrationEvent::query()->sole();

    expect($event->summary)->not->toContain('hunter2')
        ->and(app(ServerSnapshot::class)->read($server)['reachable'])->toBeFalse();
});

test('the summary is truncated to 500 characters', function () {
    $server = fakeServer();

    app(RecordIntegrationFailure::class)->handle($server, IntegrationEventType::IngestFailed, str_repeat('x', 800));

    expect(mb_strlen(IntegrationEvent::query()->sole()->summary))->toBe(500);
});

test('five consecutive failures open one alert and send one mail', function () {
    Mail::fake();
    User::factory()->admin()->create();
    failingPanel();
    $server = zinoServer();

    pollFailures($server, 4);
    expect(Alert::query()->count())->toBe(0);
    Mail::assertNothingQueued();

    pollFailures($server, 1);

    $alert = Alert::query()->sole();
    expect($alert->type)->toBe(AlertType::IntegrationFailing)
        ->and($alert->status)->toBe(AlertStatus::Open)
        ->and($alert->subject_id)->toBe($server->id)
        ->and($alert->server_id)->toBe($server->id);
    Mail::assertQueued(IntegrationFailingMail::class, 1);

    pollFailures($server, 3);

    expect(Alert::query()->count())->toBe(1);
    Mail::assertQueued(IntegrationFailingMail::class, 1);
});

test('the mail is sent again after an hour', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $server = fakeServer();
    $failure = app(RecordIntegrationFailure::class);

    foreach (range(1, 5) as $_) {
        $failure->handle($server, IntegrationEventType::PollFailed, 'boom');
    }
    Mail::assertQueued(IntegrationFailingMail::class, 1);

    $this->travel(3601)->seconds();
    $failure->handle($server, IntegrationEventType::PollFailed, 'boom');

    Mail::assertQueued(IntegrationFailingMail::class, 2);
});

test('the mail is limited per type', function () {
    Mail::fake();
    User::factory()->admin()->create();
    $server = fakeServer();
    $failure = app(RecordIntegrationFailure::class);

    foreach (range(1, 5) as $_) {
        $failure->handle($server, IntegrationEventType::PollFailed, 'boom');
        $failure->handle($server, IntegrationEventType::IngestFailed, 'boom');
    }

    Mail::assertQueued(IntegrationFailingMail::class, 2);
    expect(Alert::query()->count())->toBe(1);
});

test('the mail renders in both languages', function () {
    $server = fakeServer();

    foreach (['es', 'en'] as $locale) {
        app()->setLocale($locale);
        $html = (new IntegrationFailingMail($server, IntegrationEventType::PollFailed, 'HTTP 500', 5))->render();

        expect($html)->toContain($server->name)->toContain('HTTP 500')->not->toContain('mail.integration');
    }
});

test('a success resets the counter and closes the alert', function () {
    Mail::fake();
    $down = true;
    failingPanel($down);
    $server = zinoServer();
    pollFailures($server, 5);
    expect(Alert::query()->open()->count())->toBe(1);

    $down = false;
    app(PollServer::class)->handle($server, CarbonImmutable::now()->addMinutes(2));

    expect(Cache::get(IntegrationEventType::PollFailed->failuresKey($server)))->toBeNull()
        ->and(Alert::query()->open()->count())->toBe(0)
        ->and(Alert::query()->sole()->status)->toBe(AlertStatus::Closed);
});

test('a success of one type keeps the alert open while another type is still failing', function () {
    $server = fakeServer();
    $failure = app(RecordIntegrationFailure::class);

    foreach (range(1, 5) as $_) {
        $failure->handle($server, IntegrationEventType::PollFailed, 'boom');
        $failure->handle($server, IntegrationEventType::IngestFailed, 'boom');
    }

    app(RecordIntegrationSuccess::class)->handle($server, IntegrationEventType::PollFailed);
    expect(Alert::query()->open()->count())->toBe(1);

    app(RecordIntegrationSuccess::class)->handle($server, IntegrationEventType::IngestFailed);
    expect(Alert::query()->open()->count())->toBe(0);
});

test('an unreachable server is polled at most once a minute', function () {
    failingPanel();
    $server = zinoServer();
    $breaker = new ServerCircuitBreaker;
    foreach (range(1, 5) as $_) {
        $breaker->recordFailure($server);
    }
    $now = CarbonImmutable::parse('2026-11-10 12:00:00');
    $poll = app(PollServer::class);

    expect($poll->handle($server, $now))->toBeTrue()
        ->and($poll->handle($server, $now->addSeconds(10)))->toBeFalse()
        ->and($poll->handle($server, $now->addSeconds(59)))->toBeFalse()
        ->and($poll->handle($server, $now->addSeconds(60)))->toBeTrue();
});

test('a reachable server is polled on every run', function () {
    $server = fakeServer();
    $now = CarbonImmutable::now();

    expect(app(PollServer::class)->handle($server, $now))->toBeTrue()
        ->and(app(PollServer::class)->handle($server, $now->addSeconds(10)))->toBeTrue();
});

test('integration events older than 30 days are pruned', function () {
    $old = IntegrationEvent::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = IntegrationEvent::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [IntegrationEvent::class]])->assertSuccessful();

    expect(IntegrationEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(IntegrationEvent::query()->whereKey($recent->id)->exists())->toBeTrue();
});

test('servers:poll is scheduled every ten seconds', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'servers:poll'));

    expect($event)->not->toBeNull()
        ->and($event->repeatSeconds)->toBe(10)
        ->and($event->withoutOverlapping)->toBeTrue();
});
