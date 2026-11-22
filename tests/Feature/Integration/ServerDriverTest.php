<?php

declare(strict_types=1);

use App\Enums\RaceDurationType;
use App\Enums\ServerDriverType;
use App\Enums\TrackMode;
use App\Enums\WeatherType;
use App\Integration\Server\Data\Catalog;
use App\Integration\Server\Data\CatalogCar;
use App\Integration\Server\Data\CatalogTrack;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ConfigSnapshot;
use App\Integration\Server\Data\LiveDriver;
use App\Integration\Server\Data\LiveSnapshot;
use App\Integration\Server\Data\LogTail;
use App\Integration\Server\Data\OperationResult;
use App\Integration\Server\Data\ServerStatusReport;
use App\Integration\Server\FakeDriver;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverManager;
use App\Models\Server;
use Database\Seeders\ServerSeeder;

require_once __DIR__.'/ZinoPanelHelpers.php';

dataset('drivers', [
    'fake' => [fn (): ServerDriver => new FakeDriver],
    'zino' => [function (): ServerDriver {
        zinoFake();

        return zinoDriver();
    }],
]);

function samplePlan(): ConfigPlan
{
    return new ConfigPlan(TrackMode::RaceWeekend, 'Monza|GP|GP Race|5793', ['preset_gtam_mech_1'], 'FastGrid | Race Fair, Get Faster', 24, 600, 1200, RaceDurationType::Time, null, null, true, 14, WeatherType::Clear);
}

test('the driver reports its status', function (Closure $make) {
    $status = $make()->status();

    expect($status)->toBeInstanceOf(ServerStatusReport::class)
        ->and($status->running)->toBeBool()
        ->and($status->state)->toBeString();
})->with('drivers');

test('the driver reports a live snapshot without steam ids', function (Closure $make) {
    $live = $make()->live();

    expect($live)->toBeInstanceOf(LiveSnapshot::class)
        ->and($live->players)->toBeInt()
        ->and($live->drivers)->each->toBeInstanceOf(LiveDriver::class);

    foreach ($live->drivers as $driver) {
        expect(array_keys(get_object_vars($driver)))->toBe(['carRef', 'name', 'number', 'laps', 'lastLapMs', 'bestLapMs']);
    }
})->with('drivers');

test('the driver returns the requested log lines', function (Closure $make) {
    $tail = $make()->logTail(1);

    expect($tail)->toBeInstanceOf(LogTail::class)
        ->and($tail->lines)->toHaveCount(1)
        ->and($tail->lines)->each->toBeString();
})->with('drivers');

test('the driver exposes an opaque catalog', function (Closure $make) {
    $catalog = $make()->catalog();

    expect($catalog)->toBeInstanceOf(Catalog::class)
        ->and($catalog->cars)->not->toBeEmpty()->each->toBeInstanceOf(CatalogCar::class)
        ->and($catalog->tracks)->not->toBeEmpty()->each->toBeInstanceOf(CatalogTrack::class)
        ->and(collect($catalog->tracks)->pluck('mode')->unique()->all())->toContain(TrackMode::Practice, TrackMode::RaceWeekend)
        ->and($catalog->cars[0]->carRef)->toBeString()
        ->and($catalog->tracks[0]->trackRef)->toBeString()
        ->and($catalog->tracks[0]->lengthM)->toBeInt()
        ->and($catalog->tracks[0]->maxPitSlot)->toBeInt();
})->with('drivers');

test('the driver applies a plan and returns it sanitized', function (Closure $make) {
    $driver = $make();

    $result = $driver->apply(samplePlan());
    $config = $driver->currentConfig();

    expect($result)->toBeInstanceOf(OperationResult::class)
        ->and($result->ok)->toBeTrue()
        ->and($config)->toBeInstanceOf(ConfigSnapshot::class)
        ->and($config->mode)->toBe(TrackMode::RaceWeekend)
        ->and($config->trackRef)->toBe('Monza|GP|GP Race|5793')
        ->and($config->carRefs)->toBe(['preset_gtam_mech_1'])
        ->and($config->serverName)->toBe('FastGrid | Race Fair, Get Faster')
        ->and($config->maxPlayers)->toBe(24)
        ->and($config->qualifyingSeconds)->toBe(600)
        ->and($config->raceSeconds)->toBe(1200)
        ->and($config->practiceSeconds)->toBeNull()
        ->and($config->cycleEnabled)->toBeTrue()
        ->and(array_keys(get_object_vars($config)))->not->toContain('password', 'driverPassword');
})->with('drivers');

test('the driver can be started', function (Closure $make) {
    $result = $make()->start();

    expect($result)->toBeInstanceOf(OperationResult::class)->and($result->ok)->toBeTrue();
})->with('drivers');

test('the manager resolves the fake driver', function () {
    $server = Server::factory()->create(['driver' => ServerDriverType::Fake]);

    expect(app(ServerDriverManager::class)->for($server))->toBeInstanceOf(FakeDriver::class);
});

test('the server casts its driver to the enum', function () {
    $server = Server::factory()->create(['driver' => 'zino_panel']);

    expect($server->fresh()->driver)->toBe(ServerDriverType::ZinoPanel);
});

test('the server seeder is idempotent', function () {
    $this->seed(ServerSeeder::class);
    $this->seed(ServerSeeder::class);

    $server = Server::query()->where('slug', 'main')->sole();

    expect(Server::query()->count())->toBe(1)
        ->and($server->name)->toBe('FastGrid')
        ->and($server->ingame_prefix)->toBe('FastGrid')
        ->and($server->max_players)->toBe(24)
        ->and($server->credentials_ref)->toBe('main')
        ->and($server->driver)->toBe(ServerDriverType::Fake)
        ->and($server->base_url)->toBeNull();
});

test('the servers config reads every value from the environment', function () {
    $source = (string) file_get_contents(config_path('servers.php'));

    expect($source)->toContain("env('SERVER_URL')", "env('SERVER_USER')", "env('SERVER_PASS')")
        ->and($source)->not->toContain('http')
        ->and(substr_count($source, "=> env('"))->toBe(3)
        ->and(substr_count($source, '=>'))->toBe(4);
});
