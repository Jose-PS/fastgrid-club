<?php

declare(strict_types=1);

use App\Enums\TrackMode;
use App\Models\Car;
use App\Models\Track;

test('a race track with a practice pair is proposable', function () {
    $track = Track::factory()->withPracticePair()->create();

    expect(Track::proposable()->pluck('id')->all())->toBe([$track->id]);
});

test('a race track without a practice pair is not proposable', function () {
    Track::factory()->create();

    expect(Track::proposable()->count())->toBe(0);
});

test('inactive and not proposable race tracks are excluded', function () {
    Track::factory()->withPracticePair()->inactive()->create();
    Track::factory()->withPracticePair()->notProposable()->create();

    expect(Track::proposable()->count())->toBe(0);
});

test('practice tracks are never proposable', function () {
    $practice = Track::factory()->practice()->create();
    Track::factory()->practice()->pairedWith($practice)->create();

    expect(Track::proposable()->count())->toBe(0);
});

test('a race track exposes its paired practice track', function () {
    $practice = Track::factory()->practice()->create();
    $race = Track::factory()->raceWeekend()->pairedWith($practice)->create();

    expect($race->pairedTrack->is($practice))->toBeTrue()
        ->and($practice->pairedTrack)->toBeNull()
        ->and($practice->mode)->toBe(TrackMode::Practice);
});

test('deleting the practice track clears the pairing', function () {
    $practice = Track::factory()->practice()->create();
    $race = Track::factory()->pairedWith($practice)->create();

    $practice->delete();

    expect($race->fresh()->paired_track_id)->toBeNull();
});

test('only active, proposable, non mod cars are proposable', function () {
    $valid = Car::factory()->create();
    Car::factory()->mod()->create();
    Car::factory()->inactive()->create();
    Car::factory()->notProposable()->create();

    expect(Car::proposable()->pluck('id')->all())->toBe([$valid->id]);
});

test('car classes are cast to an array', function () {
    $car = Car::factory()->create(['classes' => ['GT3', 'Cup']]);

    expect($car->fresh()->classes)->toBe(['GT3', 'Cup']);
});
