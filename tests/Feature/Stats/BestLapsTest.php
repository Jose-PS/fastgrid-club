<?php

declare(strict_types=1);

use App\Actions\Stats\RebuildBestLaps;
use App\Actions\Stats\RecordBestLaps;
use App\Models\BestLap;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\Track;
use Illuminate\Support\Carbon;

function bestLapSetup(): array
{
    return [Track::factory()->create(), Car::factory()->create(), Driver::factory()->create()];
}

function recordLap(Track $track, Car $car, Driver $driver, int $ms, ?bool $valid = true, ?string $at = null): Lap
{
    $lap = Lap::factory()->create([
        'track_id' => $track->id,
        'car_id' => $car->id,
        'driver_id' => $driver->id,
        'lap_ms' => $ms,
        'is_valid' => $valid,
        'recorded_at' => Carbon::parse($at ?? '2026-11-03 10:00:00'),
    ]);

    app(RecordBestLaps::class)->handle([$lap]);

    return $lap;
}

test('the first valid lap becomes the best lap', function () {
    [$track, $car, $driver] = bestLapSetup();

    $lap = recordLap($track, $car, $driver, 91000);

    $best = BestLap::query()->sole();

    expect($best->lap_ms)->toBe(91000)
        ->and($best->lap_id)->toBe($lap->id)
        ->and($best->driver_id)->toBe($driver->id);
});

test('a faster lap replaces the best lap and a slower one does not', function () {
    [$track, $car, $driver] = bestLapSetup();

    recordLap($track, $car, $driver, 91000);
    $faster = recordLap($track, $car, $driver, 90000, at: '2026-11-03 11:00:00');
    recordLap($track, $car, $driver, 95000, at: '2026-11-03 12:00:00');

    $best = BestLap::query()->sole();

    expect($best->lap_ms)->toBe(90000)
        ->and($best->lap_id)->toBe($faster->id)
        ->and($best->achieved_at->equalTo(Carbon::parse('2026-11-03 11:00:00')))->toBeTrue();
});

test('a tie keeps the earlier lap', function () {
    [$track, $car, $driver] = bestLapSetup();

    $first = recordLap($track, $car, $driver, 91000);
    recordLap($track, $car, $driver, 91000, at: '2026-11-03 11:00:00');

    expect(BestLap::query()->sole()->lap_id)->toBe($first->id);
});

test('an invalid lap does not count but an unknown validity does', function () {
    [$track, $car, $driver] = bestLapSetup();

    recordLap($track, $car, $driver, 80000, valid: false);

    expect(BestLap::query()->count())->toBe(0);

    recordLap($track, $car, $driver, 92000, valid: null);

    expect(BestLap::query()->sole()->lap_ms)->toBe(92000);
});

test('a lap without track or car does not count', function () {
    $driver = Driver::factory()->create();
    $lap = Lap::factory()->create(['driver_id' => $driver->id, 'track_id' => null, 'car_id' => Car::factory()->create()->id]);
    $other = Lap::factory()->create(['driver_id' => $driver->id, 'track_id' => Track::factory()->create()->id, 'car_id' => null]);

    expect(app(RecordBestLaps::class)->handle([$lap, $other]))->toBe(0)
        ->and(BestLap::query()->count())->toBe(0);
});

test('a batch keeps one best lap per track car and driver', function () {
    [$track, $car, $driver] = bestLapSetup();
    $other = Driver::factory()->create();

    $laps = [
        Lap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 93000]),
        Lap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 91000]),
        Lap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $other->id, 'lap_ms' => 94000]),
    ];

    app(RecordBestLaps::class)->handle($laps);

    expect(BestLap::query()->count())->toBe(2)
        ->and(BestLap::query()->where('driver_id', $driver->id)->value('lap_ms'))->toBe(91000);
});

test('rebuilding from laps only ever improves the existing records', function () {
    [$track, $car, $driver] = bestLapSetup();
    $other = Driver::factory()->create();

    BestLap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 85000]);
    Lap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 90000]);
    Lap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $other->id, 'lap_ms' => 91000]);
    Lap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $other->id, 'lap_ms' => 89000]);
    Lap::factory()->create(['track_id' => $track->id, 'car_id' => $car->id, 'driver_id' => $other->id, 'lap_ms' => 70000, 'is_valid' => false]);

    app(RebuildBestLaps::class)->handle();

    expect(BestLap::query()->where('driver_id', $driver->id)->value('lap_ms'))->toBe(85000)
        ->and(BestLap::query()->where('driver_id', $other->id)->value('lap_ms'))->toBe(89000);

    $this->artisan('stats:rebuild-best-laps')->assertSuccessful();
});

test('laps on a race track and on its practice pair share one best lap on the practice track', function () {
    $car = Car::factory()->create();
    $driver = Driver::factory()->create();
    $practice = Track::factory()->practice()->create();
    $race = Track::factory()->pairedWith($practice)->create();

    recordLap($practice, $car, $driver, 91000);
    $fastest = recordLap($race, $car, $driver, 89000, at: '2026-11-03 11:00:00');

    $best = BestLap::query()->sole();

    expect($best->track_id)->toBe($practice->id)
        ->and($best->lap_ms)->toBe(89000)
        ->and($best->lap_id)->toBe($fastest->id);
});

test('rebuilding moves a best lap stored on a race track to its practice pair', function () {
    $car = Car::factory()->create();
    $driver = Driver::factory()->create();
    $practice = Track::factory()->practice()->create();
    $race = Track::factory()->pairedWith($practice)->create();

    BestLap::factory()->create(['track_id' => $race->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 92000]);
    $fastest = Lap::factory()->create(['track_id' => $practice->id, 'car_id' => $car->id, 'driver_id' => $driver->id, 'lap_ms' => 90000]);

    app(RebuildBestLaps::class)->handle();

    $best = BestLap::query()->sole();

    expect($best->track_id)->toBe($practice->id)
        ->and($best->lap_ms)->toBe(90000)
        ->and($best->lap_id)->toBe($fastest->id);
});
