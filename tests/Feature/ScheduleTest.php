<?php

declare(strict_types=1);

use App\Enums\RaceDurationType;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use Illuminate\Support\Carbon;

test('the migration seeds the initial version with the current weekly schedule', function () {
    $version = ScheduleVersion::query()->sole();

    expect($version->effective_from->equalTo(Carbon::parse('2026-01-01 00:00', 'Europe/Madrid')))->toBeTrue()
        ->and($version->created_by)->toBeNull();

    $segments = $version->segments;

    expect($segments)->toHaveCount(8);

    foreach (range(1, 7) as $weekday) {
        $midnight = $segments->first(fn (ScheduleSegment $s) => $s->weekday === $weekday && str_starts_with($s->starts_at, '00:00'));

        expect($midnight)->not->toBeNull()
            ->and($midnight->practice_minutes)->toBe(120)
            ->and($midnight->qualifying_minutes)->toBeNull()
            ->and($midnight->race_duration_type)->toBeNull()
            ->and($midnight->race_minutes)->toBeNull()
            ->and($midnight->race_laps)->toBeNull();
    }

    $evening = $segments->sole(fn (ScheduleSegment $s) => str_starts_with($s->starts_at, '18:00'));

    expect($evening->weekday)->toBe(6)
        ->and($evening->practice_minutes)->toBeNull()
        ->and($evening->qualifying_minutes)->toBe(30)
        ->and($evening->race_duration_type)->toBe(RaceDurationType::Time)
        ->and($evening->race_minutes)->toBe(20)
        ->and($evening->race_laps)->toBeNull();
});

test('segments are ordered by weekday and start time', function () {
    $version = ScheduleVersion::factory()->create();
    ScheduleSegment::factory()->for($version, 'version')->create(['weekday' => 3, 'starts_at' => '10:00']);
    ScheduleSegment::factory()->for($version, 'version')->create(['weekday' => 1, 'starts_at' => '20:00']);
    ScheduleSegment::factory()->for($version, 'version')->create(['weekday' => 1, 'starts_at' => '08:00']);

    $order = $version->segments->map(fn (ScheduleSegment $s) => $s->weekday.'@'.substr($s->starts_at, 0, 5))->all();

    expect($order)->toBe(['1@08:00', '1@20:00', '3@10:00']);
});

test('activeAt returns the initial version before any later version', function () {
    $initial = ScheduleVersion::query()->sole();

    expect(ScheduleVersion::activeAt(Carbon::parse('2026-06-01 12:00', 'Europe/Madrid'))?->id)->toBe($initial->id)
        ->and(ScheduleVersion::activeAt(Carbon::parse('2025-01-01', 'Europe/Madrid')))->toBeNull();
});

test('activeAt ignores future versions until their date and picks the latest one after', function () {
    $initial = ScheduleVersion::query()->sole();
    $future = ScheduleVersion::factory()->create(['effective_from' => Carbon::parse('2027-03-01 00:00', 'Europe/Madrid')]);
    $later = ScheduleVersion::factory()->create(['effective_from' => Carbon::parse('2027-06-01 00:00', 'Europe/Madrid')]);

    expect(ScheduleVersion::activeAt(Carbon::parse('2027-02-28 23:59', 'Europe/Madrid'))->id)->toBe($initial->id)
        ->and(ScheduleVersion::activeAt(Carbon::parse('2027-03-01 00:00', 'Europe/Madrid'))->id)->toBe($future->id)
        ->and(ScheduleVersion::activeAt(Carbon::parse('2027-06-01 00:00', 'Europe/Madrid'))->id)->toBe($later->id);
});

test('activeAt breaks ties on the same effective date with the higher id', function () {
    $date = Carbon::parse('2027-03-01 00:00', 'Europe/Madrid');
    ScheduleVersion::factory()->create(['effective_from' => $date]);
    $second = ScheduleVersion::factory()->create(['effective_from' => $date]);

    expect(ScheduleVersion::activeAt($date->copy()->addDay())->id)->toBe($second->id);
});

test('the race duration type is cast to the enum', function () {
    $segment = ScheduleSegment::factory()->create(['race_duration_type' => 'laps', 'race_laps' => 5]);

    expect($segment->fresh()->race_duration_type)->toBe(RaceDurationType::Laps)
        ->and($segment->fresh()->race_laps)->toBe(5);

    $segment->update(['race_duration_type' => RaceDurationType::Time]);

    expect($segment->fresh()->getRawOriginal('race_duration_type'))->toBe('time');
});

test('deleting a version deletes its segments', function () {
    $version = ScheduleVersion::factory()->create();
    ScheduleSegment::factory()->for($version, 'version')->count(2)->sequence(['starts_at' => '00:00'], ['starts_at' => '06:00'])->create();
    $other = ScheduleSegment::factory()->create();

    $version->delete();

    expect(ScheduleSegment::query()->where('schedule_version_id', $version->id)->count())->toBe(0)
        ->and(ScheduleSegment::query()->whereKey($other->id)->exists())->toBeTrue();
});
