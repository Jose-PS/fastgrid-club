<?php

declare(strict_types=1);

use App\Models\Car;
use App\Models\Server;
use App\Models\Track;
use App\Models\Week;
use App\Support\IngameName;

function weekWithCars(array $classesPerCar, array $weekAttributes = [], string $trackName = 'Spa'): array
{
    $server = Server::factory()->create(['ingame_prefix' => 'FastGrid']);
    $track = Track::factory()->create(['display' => $trackName]);
    $week = Week::factory()->for($server)->create(['track_id' => $track->id] + $weekAttributes);
    foreach ($classesPerCar as $name => $classes) {
        $week->cars()->attach(Car::factory()->create(['display_name' => $name, 'classes' => $classes])->id);
    }

    return [$server, $week->fresh(['track', 'cars'])];
}

test('a single car gives its display name', function () {
    [$server, $week] = weekWithCars(['Ferrari 296 GT3' => ['GT3']]);

    expect(app(IngameName::class)->for($server, $week))->toBe('FastGrid | Ferrari 296 GT3 @ Spa | Race Fair, Get Faster');
});

test('several cars give their common class', function () {
    [$server, $week] = weekWithCars(['A' => ['GT3', 'GTE'], 'B' => ['GT3']]);

    expect(app(IngameName::class)->for($server, $week))->toBe('FastGrid | GT3 @ Spa | Race Fair, Get Faster');
});

test('several cars without a common class give the first class of the first car', function () {
    [$server, $week] = weekWithCars(['A' => ['GT4', 'GTE'], 'B' => ['GT3']]);

    expect(app(IngameName::class)->for($server, $week))->toBe('FastGrid | GT4 @ Spa | Race Fair, Get Faster');
});

test('the custom title is the whole name, as written', function () {
    [$server, $week] = weekWithCars(['A' => ['GT3']], ['custom_title' => 'Noche en Spa']);

    expect(app(IngameName::class)->for($server, $week))->toBe('Noche en Spa');
});

test('an empty custom title is ignored', function () {
    [$server, $week] = weekWithCars(['A' => ['GT3']], ['custom_title' => '  ']);

    expect(app(IngameName::class)->for($server, $week))->toBe('FastGrid | A @ Spa | Race Fair, Get Faster');
});

test('without a week or without track and cars it falls back to the prefix and the tagline', function () {
    $server = Server::factory()->create(['ingame_prefix' => 'FastGrid']);
    $bare = Week::factory()->for($server)->create();

    expect(app(IngameName::class)->for($server, null))->toBe('FastGrid | Race Fair, Get Faster')
        ->and(app(IngameName::class)->for($server, $bare))->toBe('FastGrid | Race Fair, Get Faster');
});

test('the custom title is trimmed and used even without a track', function () {
    $server = Server::factory()->create(['ingame_prefix' => 'FastGrid']);
    $week = Week::factory()->for($server)->create(['custom_title' => '  fastgrid.club | 911 GT3 @ Donnington | Race Fair - Vote Next Race ']);

    expect(app(IngameName::class)->for($server, $week))->toBe('fastgrid.club | 911 GT3 @ Donnington | Race Fair - Vote Next Race');
});
