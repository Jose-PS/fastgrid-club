<?php

declare(strict_types=1);

use App\Actions\Auth\LinkSteamIdentity;
use App\Actions\Auth\SignInWithSteam;
use App\Actions\Servers\IngestServerLogs;
use App\Enums\IdentityProvider;
use App\Enums\IntegrationEventType;
use App\Enums\WeekStatus;
use App\Events\SteamUnlinked;
use App\Integration\Server\Log\LogProcessor;
use App\Integration\Server\Log\SessionType;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverException;
use App\Integration\Server\ServerDriverManager;
use App\Models\Car;
use App\Models\Driver;
use App\Models\IntegrationEvent;
use App\Models\Lap;
use App\Models\LogCursor;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Week;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Two\User as SocialiteUser;

require_once __DIR__.'/ScriptedEventDriver.php';

final class ScriptedDriverManager extends ServerDriverManager
{
    public function for(Server $server): ServerDriver
    {
        return new ScriptedEventDriver;
    }
}

function logFixture(string $name): array
{
    return file(__DIR__.'/../../Fixtures/logs/'.$name, FILE_IGNORE_NEW_LINES);
}

function ingestLines(Server $server, array $lines): void
{
    ScriptedEventDriver::$lines = $lines;
    ScriptedEventDriver::$failure = null;

    app(IngestServerLogs::class)->handle($server);
}

function ingestingServer(): Server
{
    return Server::factory()->create(['is_active' => true]);
}

beforeEach(function () {
    app()->instance(ServerDriverManager::class, new ScriptedDriverManager);
    ScriptedEventDriver::reset();
});

test('a practice log is stored as 20 laps attributed by steam id', function () {
    $server = ingestingServer();

    ingestLines($server, logFixture('practice-session.log'));

    expect(Lap::query()->count())->toBe(20)
        ->and(Driver::query()->count())->toBe(2)
        ->and(Driver::query()->where('steam_id', '76561198000000001')->first()->laps()->count())->toBe(15)
        ->and(Driver::query()->where('steam_id', '76561198000000002')->first()->laps()->count())->toBe(5)
        ->and(Driver::query()->where('steam_id', '76561198000000001')->value('last_name'))->toBe('Driver 1')
        ->and(ServerSession::query()->count())->toBe(0);

    $cursor = LogCursor::query()->where('server_id', $server->id)->first();

    expect($cursor->last_event_id)->toBe(count(logFixture('practice-session.log')))
        ->and($cursor->last_gap_at)->toBeNull()
        ->and(Cache::get(IngestServerLogs::metricsKey($server))['laps'])->toBe(20);
});

test('a race weekend log stores qualifying and race sessions with one-based lap numbers', function () {
    $server = ingestingServer();

    ingestLines($server, logFixture('race-weekend.log'));

    $qualifying = ServerSession::query()->where('type', SessionType::Qualifying)->firstOrFail();
    $race = ServerSession::query()->where('type', SessionType::Race)->firstOrFail();

    expect(Lap::query()->count())->toBe(27)
        ->and(ServerSession::query()->count())->toBe(2)
        ->and(Lap::query()->where('server_session_id', $qualifying->id)->count())->toBe(9)
        ->and(Lap::query()->where('server_session_id', $race->id)->count())->toBe(18)
        ->and(Lap::query()->where('server_session_id', $race->id)->min('lap_number'))->toBe(1)
        ->and(Lap::query()->where('server_session_id', $race->id)->whereNull('lap_number')->count())->toBe(0)
        ->and(Lap::query()->where('server_session_id', $qualifying->id)->whereNotNull('lap_number')->count())->toBe(0);
});

test('ingesting the same read twice does not duplicate anything', function () {
    $server = ingestingServer();
    $lines = logFixture('race-weekend.log');

    ingestLines($server, $lines);
    ingestLines($server, $lines);

    expect(Lap::query()->count())->toBe(27)
        ->and(ServerSession::query()->count())->toBe(2)
        ->and(Driver::query()->count())->toBe(Driver::query()->distinct('steam_id')->count('steam_id'))
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(0);
});

test('a later read continues from the cursor and stores only the new lines without a gap', function () {
    $server = ingestingServer();
    $lines = logFixture('practice-session.log');

    ingestLines($server, array_slice($lines, 0, 300));
    $firstRead = Lap::query()->count();

    ingestLines($server, $lines);

    expect($firstRead)->toBeLessThan(20)
        ->and(Lap::query()->count())->toBe(20)
        ->and(Lap::query()->distinct('source_hash')->count('source_hash'))->toBe(20)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(0)
        ->and(LogCursor::query()->first()->last_gap_at)->toBeNull();
});

test('a first event id beyond the cursor records a log gap and keeps the laps idempotent', function () {
    $server = ingestingServer();
    $lines = logFixture('practice-session.log');

    ingestLines($server, array_map(fn (int $i): string => "unrelated line {$i}", range(1, 60)));
    ScriptedEventDriver::$offset = 500;
    ingestLines($server, $lines);

    $event = IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->firstOrFail();

    expect(Lap::query()->count())->toBe(20)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(1)
        ->and($event->summary)->not->toContain('Driver')
        ->and(LogCursor::query()->first()->last_gap_at)->not->toBeNull();

    ingestLines($server, $lines);

    expect(Lap::query()->count())->toBe(20)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(1);
});

test('a session split across two reads ends up in a single row with its end', function () {
    $server = ingestingServer();
    $lines = logFixture('race-weekend.log');
    $raceCreated = collect($lines)->search(fn (string $line): bool => str_contains($line, 'InstantRaceRemote Race created'));

    ingestLines($server, array_slice($lines, 0, $raceCreated));

    $qualifying = ServerSession::query()->where('type', SessionType::Qualifying)->firstOrFail();
    expect($qualifying->ended_at)->toBeNull()
        ->and(ServerSession::query()->count())->toBe(1);

    $startedAt = $qualifying->started_at;

    ingestLines($server, $lines);

    $qualifying->refresh();

    expect(ServerSession::query()->count())->toBe(2)
        ->and($qualifying->ended_at)->not->toBeNull()
        ->and($qualifying->started_at->equalTo($startedAt))->toBeTrue()
        ->and(Lap::query()->count())->toBe(27)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(0);
});

test('the car is resolved by runtime name and the track by the current week', function () {
    $server = ingestingServer();
    $lines = logFixture('practice-session.log');
    $first = (new LogProcessor)->process($lines)->laps[0];
    $car = Car::factory()->create(['runtime_name' => $first->carRuntimeName]);

    $race = Track::factory()->withPracticePair()->create();
    Week::query()->create([
        'server_id' => $server->id,
        'status' => WeekStatus::Active,
        'starts_at' => $first->recordedAt->subDay(),
        'ends_at' => $first->recordedAt->addDays(6),
        'voting_closes_at' => $first->recordedAt->subDays(2),
        'track_id' => $race->id,
    ]);

    ingestLines($server, $lines);

    expect(Lap::query()->where('car_id', $car->id)->count())->toBeGreaterThan(0)
        ->and(Lap::query()->whereNull('car_id')->count())->toBeLessThan(20)
        ->and(Lap::query()->where('track_id', $race->paired_track_id)->count())->toBe(20);
});

test('race weekend sessions use the race weekend circuit and laps without a week have no track', function () {
    $server = ingestingServer();
    $lines = logFixture('race-weekend.log');

    ingestLines($server, $lines);

    expect(Lap::query()->whereNull('track_id')->count())->toBe(27)
        ->and(ServerSession::query()->whereNull('track_id')->count())->toBe(2);

    $other = ingestingServer();
    $first = (new LogProcessor)->process($lines)->laps[0];
    $race = Track::factory()->withPracticePair()->create();
    Week::query()->create([
        'server_id' => $other->id,
        'status' => WeekStatus::Active,
        'starts_at' => $first->recordedAt->subDay(),
        'ends_at' => $first->recordedAt->addDays(6),
        'voting_closes_at' => $first->recordedAt->subDays(2),
        'track_id' => $race->id,
    ]);

    ingestLines($other, $lines);

    expect(Lap::query()->where('server_id', $other->id)->where('track_id', $race->id)->count())->toBe(27)
        ->and(ServerSession::query()->where('server_id', $other->id)->where('track_id', $race->id)->count())->toBe(2);
});

test('linking steam fills the driver user and unlinking clears it', function () {
    $server = ingestingServer();
    ingestLines($server, logFixture('practice-session.log'));

    $steamId = '76561198000000001';
    expect(Driver::query()->where('steam_id', $steamId)->value('user_id'))->toBeNull();

    $user = User::factory()->create();
    $steamUser = (new SocialiteUser)->map(['id' => $steamId, 'nickname' => 'Piloto Uno']);

    app(LinkSteamIdentity::class)->handle($user, $steamUser);

    $driver = Driver::query()->where('steam_id', $steamId)->firstOrFail();

    expect($driver->user_id)->toBe($user->id)
        ->and($driver->laps()->count())->toBe(15);

    SteamUnlinked::dispatch($user->id, $steamId);

    expect($driver->fresh()->user_id)->toBeNull();
});

test('signing in with a new steam account links an existing driver', function () {
    $server = ingestingServer();
    ingestLines($server, logFixture('practice-session.log'));

    $steamId = '76561198000000002';
    $user = app(SignInWithSteam::class)->handle((new SocialiteUser)->map(['id' => $steamId, 'nickname' => 'Piloto Dos']));

    expect(Driver::query()->where('steam_id', $steamId)->value('user_id'))->toBe($user->id);
});

test('a new driver is linked on creation when the steam identity already exists', function () {
    $server = ingestingServer();
    $user = User::factory()->create();
    UserIdentity::factory()->create([
        'user_id' => $user->id,
        'provider' => IdentityProvider::Steam,
        'provider_id' => '76561198000000001',
    ]);

    ingestLines($server, logFixture('practice-session.log'));

    expect(Driver::query()->where('steam_id', '76561198000000001')->value('user_id'))->toBe($user->id)
        ->and(Driver::query()->where('steam_id', '76561198000000002')->value('user_id'))->toBeNull();
});

test('a driver failure records an ingest failure and does not move the cursor', function () {
    $server = ingestingServer();
    $lines = logFixture('practice-session.log');

    ingestLines($server, array_slice($lines, 0, 300));
    $cursor = LogCursor::query()->firstOrFail();
    $lastEventId = $cursor->last_event_id;
    $laps = Lap::query()->count();

    ScriptedEventDriver::$failure = new ServerDriverException('zino_panel logs: timeout');
    app(IngestServerLogs::class)->handle($server);

    $after = $cursor->fresh();

    expect(IntegrationEvent::query()->where('type', IntegrationEventType::IngestFailed)->count())->toBe(1)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::IngestFailed)->value('summary'))->toBe('zino_panel logs: timeout')
        ->and($after->last_event_id)->toBe($lastEventId)
        ->and($after->last_read_at->equalTo($cursor->last_read_at))->toBeTrue()
        ->and(Lap::query()->count())->toBe($laps);
});

test('a read advances the cursor to the last event id and the next one starts after it', function () {
    $server = ingestingServer();
    $lines = logFixture('practice-session.log');

    ingestLines($server, array_slice($lines, 0, 300));

    expect(LogCursor::query()->firstOrFail()->last_event_id)->toBe(300);

    ingestLines($server, array_slice($lines, 0, 400));

    expect(LogCursor::query()->firstOrFail()->last_event_id)->toBe(400);

    ingestLines($server, $lines);

    expect(LogCursor::query()->firstOrFail()->last_event_id)->toBe(count($lines))
        ->and(Lap::query()->count())->toBe(20);
});

test('more than one page of events is collected in a single pass', function () {
    $server = ingestingServer();
    $filler = array_map(fn (int $i): string => "filler line {$i}", range(1, IngestServerLogs::PAGE_SIZE * 2 + 10));
    $lines = [...$filler, ...logFixture('practice-session.log')];

    ingestLines($server, $lines);

    expect(Lap::query()->count())->toBe(20)
        ->and(LogCursor::query()->firstOrFail()->last_event_id)->toBe(count($lines))
        ->and(Cache::get(IngestServerLogs::metricsKey($server))['lines'])->toBe(count($lines))
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(0);
});

test('a pass stops at the page limit and the next one continues', function () {
    $server = ingestingServer();
    $total = IngestServerLogs::PAGE_SIZE * (IngestServerLogs::MAX_PAGES + 1);
    $lines = array_map(fn (int $i): string => "filler line {$i}", range(1, $total));

    ingestLines($server, $lines);

    expect(LogCursor::query()->firstOrFail()->last_event_id)->toBe(IngestServerLogs::PAGE_SIZE * IngestServerLogs::MAX_PAGES);

    ingestLines($server, $lines);

    expect(LogCursor::query()->firstOrFail()->last_event_id)->toBe($total)
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(0);
});

test('rereading events already seen does not duplicate laps or sessions', function () {
    $server = ingestingServer();
    $lines = logFixture('race-weekend.log');

    ingestLines($server, $lines);

    LogCursor::query()->firstOrFail()->update(['last_event_id' => 100]);

    ingestLines($server, $lines);

    expect(Lap::query()->count())->toBe(27)
        ->and(Lap::query()->distinct('source_hash')->count('source_hash'))->toBe(27)
        ->and(ServerSession::query()->count())->toBe(2)
        ->and(LogCursor::query()->firstOrFail()->last_event_id)->toBe(count($lines));
});

test('an empty read leaves the cursor untouched', function () {
    $server = ingestingServer();
    $lines = logFixture('practice-session.log');

    ingestLines($server, $lines);
    $cursor = LogCursor::query()->firstOrFail();

    ingestLines($server, $lines);

    expect(LogCursor::query()->firstOrFail()->last_read_at->equalTo($cursor->last_read_at))->toBeTrue()
        ->and(IntegrationEvent::query()->where('type', IntegrationEventType::LogGap)->count())->toBe(0);
});

test('laps older than 90 days are pruned and recent ones are kept', function () {
    $old = Lap::factory()->create(['recorded_at' => now()->subDays(91)]);
    $recent = Lap::factory()->create(['recorded_at' => now()->subDays(89)]);

    $this->artisan('model:prune', ['--model' => Lap::class])->assertSuccessful();

    expect(Lap::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Lap::query()->whereKey($recent->id)->exists())->toBeTrue();
});

test('the command ingests active servers only and accepts a server slug', function () {
    $active = ingestingServer();
    $inactive = Server::factory()->create(['is_active' => false]);
    ScriptedEventDriver::$lines = logFixture('practice-session.log');

    $this->artisan('servers:ingest-logs', ['server' => $inactive->slug])->assertSuccessful();
    expect(Lap::query()->count())->toBe(0);

    $this->artisan('servers:ingest-logs')->assertSuccessful();

    expect(Lap::query()->where('server_id', $active->id)->count())->toBe(20)
        ->and(Lap::query()->where('server_id', $inactive->id)->count())->toBe(0);
});
