<?php

declare(strict_types=1);

use App\Actions\Servers\IngestServerLogs;
use App\Enums\IntegrationEventType;
use App\Enums\WeekStatus;
use App\Integration\Server\Log\LogProcessor;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverManager;
use App\Models\Alert;
use App\Models\BestLap;
use App\Models\Car;
use App\Models\Driver;
use App\Models\IntegrationEvent;
use App\Models\Lap;
use App\Models\LogCursor;
use App\Models\RaceResult;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\Week;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/ScriptedEventDriver.php';

final class BackfillDriverManager extends ServerDriverManager
{
    public function for(Server $server): ServerDriver
    {
        return new ScriptedEventDriver;
    }
}

function backfillFixture(string $name): array
{
    return file(__DIR__.'/../../Fixtures/logs/'.$name, FILE_IGNORE_NEW_LINES);
}

function backfillServerWithWeek(array $lines): Server
{
    $server = Server::factory()->create(['is_active' => true]);
    $first = (new LogProcessor)->process($lines)->laps[0];
    Car::factory()->create(['runtime_name' => $first->carRuntimeName]);
    $track = Track::factory()->withPracticePair()->create();
    Week::query()->create([
        'server_id' => $server->id,
        'status' => WeekStatus::Active,
        'starts_at' => $first->recordedAt->subDay(),
        'ends_at' => $first->recordedAt->addDays(6),
        'voting_closes_at' => $first->recordedAt->subDays(2),
        'track_id' => $track->id,
    ]);

    return $server;
}

beforeEach(function () {
    app()->instance(ServerDriverManager::class, new BackfillDriverManager);
    ScriptedEventDriver::reset();
});

test('backfill after a gap inserts the missed laps and updates the best laps', function () {
    $lines = backfillFixture('practice-session.log');
    $server = backfillServerWithWeek($lines);

    ScriptedEventDriver::$lines = array_slice($lines, 0, 150);
    app(IngestServerLogs::class)->handle($server);
    $ingested = Lap::query()->count();

    ScriptedEventDriver::$lines = $lines;

    $this->artisan('servers:ingest-logs', ['server' => $server->slug, '--backfill' => true, '--since' => '2026-10-20 17:00'])
        ->expectsOutputToContain('processed 416 lines, '.(20 - $ingested).' new laps')
        ->assertSuccessful();

    expect($ingested)->toBeLessThan(20)
        ->and(Lap::query()->count())->toBe(20)
        ->and(Lap::query()->distinct('source_hash')->count('source_hash'))->toBe(20)
        ->and(BestLap::query()->min('lap_ms'))->toBe((int) Lap::query()->where('is_valid', true)->whereNotNull('car_id')->whereNotNull('track_id')->min('lap_ms'));
});

test('running backfill twice does not duplicate laps, sessions or results', function () {
    $lines = backfillFixture('race-weekend.log');
    $server = backfillServerWithWeek($lines);
    ScriptedEventDriver::$lines = $lines;

    $arguments = ['server' => $server->slug, '--backfill' => true, '--since' => '2026-10-01 00:00'];

    $this->artisan('servers:ingest-logs', $arguments)->assertSuccessful();

    $counts = fn (): array => [
        Lap::query()->count(),
        ServerSession::query()->count(),
        RaceResult::query()->count(),
        Driver::query()->count(),
        BestLap::query()->count(),
    ];

    $first = $counts();
    $drivers = Driver::query()->orderBy('id')->get(['id', 'last_name', 'first_seen_at', 'last_seen_at'])->toArray();

    $this->artisan('servers:ingest-logs', $arguments)
        ->expectsOutputToContain('0 new laps')
        ->assertSuccessful();

    expect($first[0])->toBe(27)
        ->and($first[1])->toBe(2)
        ->and($counts())->toBe($first)
        ->and(Driver::query()->orderBy('id')->get(['id', 'last_name', 'first_seen_at', 'last_seen_at'])->toArray())->toBe($drivers);
});

test('backfill does not touch the log cursor, alerts or gap events', function () {
    $lines = backfillFixture('practice-session.log');
    $server = Server::factory()->create(['is_active' => true]);

    ScriptedEventDriver::$lines = array_slice($lines, 0, 150);
    app(IngestServerLogs::class)->handle($server);
    $cursor = LogCursor::query()->where('server_id', $server->id)->firstOrFail()->only(['last_event_id', 'processor_state', 'last_read_at', 'last_gap_at']);
    $events = IntegrationEvent::query()->count();

    ScriptedEventDriver::$lines = $lines;

    $this->artisan('servers:ingest-logs', ['server' => $server->slug, '--backfill' => true, '--since' => '2026-10-20 00:00'])->assertSuccessful();

    expect(LogCursor::query()->where('server_id', $server->id)->firstOrFail()->only(['last_event_id', 'processor_state', 'last_read_at', 'last_gap_at']))->toEqual($cursor)
        ->and(LogCursor::query()->count())->toBe(1)
        ->and(IntegrationEvent::query()->count())->toBe($events)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(0)
        ->and(Alert::query()->count())->toBe(0);
});

test('lines before the since instant are ignored', function () {
    $lines = backfillFixture('practice-session.log');
    $server = Server::factory()->create(['is_active' => true]);
    ScriptedEventDriver::$lines = $lines;

    $this->artisan('servers:ingest-logs', ['server' => $server->slug, '--backfill' => true, '--since' => '2026-10-20 17:40:00'])->assertSuccessful();

    expect(Lap::query()->count())->toBeGreaterThan(0)
        ->and(Lap::query()->count())->toBeLessThan(20)
        ->and(Lap::query()->where('recorded_at', '<', '2026-10-20 17:40:00')->count())->toBe(0);

    $this->artisan('servers:ingest-logs', ['server' => $server->slug, '--backfill' => true, '--since' => '2030-01-01 00:00'])
        ->expectsOutputToContain('processed 0 lines, 0 new laps')
        ->assertSuccessful();
});

test('backfill requires since and fails while the ingest lock is held', function () {
    $server = Server::factory()->create(['is_active' => true]);

    $this->artisan('servers:ingest-logs', ['server' => $server->slug, '--backfill' => true])
        ->expectsOutputToContain('--since')
        ->assertFailed();

    $lock = Cache::lock("server:{$server->id}:ingest", 60);
    $lock->get();

    $this->artisan('servers:ingest-logs', ['server' => $server->slug, '--backfill' => true, '--since' => '2026-10-20 00:00'])
        ->expectsOutputToContain('lock')
        ->assertFailed();

    $lock->release();
});
