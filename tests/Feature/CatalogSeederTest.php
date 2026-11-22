<?php

declare(strict_types=1);

use App\Enums\TrackMode;
use App\Models\Car;
use App\Models\CatalogSync;
use App\Models\Track;
use Database\Seeders\CatalogSeeder;

function zinoMetadata(): array
{
    return json_decode(file_get_contents(database_path('data/zino/metadata.json')), true);
}

test('the catalog seeder is idempotent', function () {
    $this->seed(CatalogSeeder::class);
    $counts = [Track::count(), Car::count()];

    $this->seed(CatalogSeeder::class);

    expect([Track::count(), Car::count()])->toBe($counts);
});

test('the catalog seeder loads every car and circuit of the zino metadata', function () {
    $this->seed(CatalogSeeder::class);

    expect(Car::count())->toBe(count(zinoMetadata()['cars']))
        ->and(Track::where('mode', TrackMode::Practice)->count())->toBe(count(zinoMetadata()['tracks']['practice']))
        ->and(Track::where('mode', TrackMode::RaceWeekend)->count())->toBe(count(zinoMetadata()['tracks']['race_weekend']))
        ->and(Car::where('is_mod', true)->exists())->toBeFalse()
        ->and(Car::proposable()->count())->toBe(Car::count());
});

test('a car is mapped with pi, type, era, engine, brand and classes', function () {
    $this->seed(CatalogSeeder::class);

    $car = Car::where('display_name', 'Alfa Romeo Giulia Sprint GTA - Standard')->firstOrFail();

    expect((float) $car->pi)->toBe(7.7)
        ->and($car->type)->toBe('race')
        ->and($car->era)->toBe('vintage')
        ->and($car->engine)->toBe('ice')
        ->and($car->brand)->toBe('Alfa Romeo')
        ->and($car->classes)->toBe([])
        ->and($car->runtime_name)->toBe('ks_alfa_romeo_giulia_gta_sprint');
});

test('racing classes follow the zino rules', function () {
    $this->seed(CatalogSeeder::class);

    expect(Car::where('display_name', 'Audi R8 LMS GT3 Evo II - GT3')->value('classes'))->toBe(['gt3'])
        ->and(Car::where('display_name', 'Porsche 911 GT3 Cup (992) - ABS')->value('classes'))->toBe(['cup'])
        ->and(Car::where('display_name', 'Ferrari SF-25 - F1 2025')->value('classes'))->toBe(['f1'])
        ->and(Car::where('display_name', 'KTM X-Bow GT4 - GT4')->value('classes'))->toBe(['gt4'])
        ->and(Car::where('display_name', 'Toyota GR86 - Standard')->value('classes'))->toBe([])
        ->and(Car::where('display_name', 'Dallara EXP - Standard')->value('type'))->toBe('track')
        ->and(Car::where('display_name', 'Alfa Romeo Junior - Elettrica 280 CV Veloce')->value('engine'))->not->toBeNull();
});

test('the brand takes the first word except for multi word brands', function () {
    expect(Car::brandFromDisplayName('Alfa Romeo Junior - Standard'))->toBe('Alfa Romeo')
        ->and(Car::brandFromDisplayName('Aston Martin Vantage GT3'))->toBe('Aston Martin')
        ->and(Car::brandFromDisplayName('Mercedes-AMG GT2 - GT2'))->toBe('Mercedes-AMG')
        ->and(Car::brandFromDisplayName('Porsche 935 - GT2'))->toBe('Porsche');
});

test('every race circuit is paired with the practice of the same track and layout', function () {
    $this->seed(CatalogSeeder::class);

    $race = Track::where('mode', TrackMode::RaceWeekend)
        ->where('track', 'Brands Hatch')->where('layout', 'GP')->firstOrFail();

    expect($race->token)->toBe('Brands Hatch|GP|GP Race|3916')
        ->and($race->display)->toBe('Brands Hatch GP')
        ->and($race->length_m)->toBe(3916)
        ->and($race->max_pit_slot)->toBe(32)
        ->and($race->pairedTrack->mode)->toBe(TrackMode::Practice)
        ->and($race->pairedTrack->token)->toBe('Brands Hatch|GP|GP Time Attack|3916')
        ->and(Track::where('mode', TrackMode::RaceWeekend)->whereNull('paired_track_id')->count())->toBe(0)
        ->and(Track::proposable()->count())->toBe(35);
});

test('rows missing from the zino metadata are deactivated instead of deleted', function () {
    $oldTrack = Track::factory()->create(['track' => 'legacy', 'layout' => 'old']);
    $oldCar = Car::factory()->create();

    $this->seed(CatalogSeeder::class);

    expect($oldTrack->fresh()->active)->toBeFalse()
        ->and($oldCar->fresh()->active)->toBeFalse()
        ->and(Track::proposable()->whereKey($oldTrack->id)->exists())->toBeFalse();
});

test('every track of the zino metadata has a country in the map', function () {
    $countries = json_decode(file_get_contents(database_path('data/track-countries.json')), true);
    $tracks = collect(zinoMetadata()['tracks'])
        ->flatten(1)
        ->pluck('track')
        ->unique();

    expect($tracks->reject(fn (string $track): bool => isset($countries[$track]))->values()->all())->toBe([])
        ->and(collect($countries)->every(fn (string $code): bool => preg_match('/^[A-Z]{2}$/', $code) === 1))->toBeTrue();
});

test('the seeder stores the country of every circuit', function () {
    $this->seed(CatalogSeeder::class);

    expect(Track::whereNull('country')->count())->toBe(0)
        ->and(Track::where('track', 'Brands Hatch')->value('country'))->toBe('GB')
        ->and(Track::where('track', 'Circuit de Spa Francorchamps')->value('country'))->toBe('BE')
        ->and(Track::where('track', 'Mount Panorama')->value('country'))->toBe('AU');
});

test('the seeder fails when a circuit has no country', function () {
    $map = json_decode(file_get_contents(database_path('data/track-countries.json')), true);
    unset($map['Monza']);

    expect(fn () => (new CatalogSeeder)->withCountries($map)->run())
        ->toThrow(RuntimeException::class, 'Monza');
});

test('the seeder goes through the catalog sync of the main server', function () {
    $this->seed(CatalogSeeder::class);

    $sync = CatalogSync::query()->sole();

    expect($sync->server->slug)->toBe('main')
        ->and($sync->summary['cars']['created'])->toBe(100)
        ->and($sync->summary['unpaired_race_tracks'])->toBe([]);
});

test('the database seeder leaves the catalog loaded', function () {
    $this->seed();

    expect(Car::count())->toBe(100)
        ->and(Track::proposable()->count())->toBe(35);
});
