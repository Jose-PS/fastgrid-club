<?php

declare(strict_types=1);

use App\Enums\RaceDurationType;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Support\ScheduleWindow;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function scheduleLocal(string $datetime): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, 'Europe/Madrid');
}

function scheduleUtc(CarbonImmutable $instant): string
{
    return $instant->setTimezone('UTC')->format('Y-m-d H:i');
}

function scheduleVersionWith(string $effectiveFrom, array $segments): ScheduleVersion
{
    $version = ScheduleVersion::factory()->create(['effective_from' => scheduleLocal($effectiveFrom)->setTimezone('UTC')]);

    foreach ($segments as $segment) {
        ScheduleSegment::factory()->create(array_merge(['schedule_version_id' => $version->id], $segment));
    }

    return $version;
}

function scheduleWindowsOf(string $localMonday): array
{
    return (new WeekCalendar)->scheduleWindows(scheduleLocal($localMonday));
}

function scheduleSpans(array $windows): array
{
    return array_map(fn (ScheduleWindow $w): string => scheduleUtc($w->startsAt).'/'.scheduleUtc($w->endsAt), $windows);
}

test('the seeded version yields the eight windows of a normal week', function () {
    $windows = scheduleWindowsOf('2026-11-09');

    expect(scheduleSpans($windows))->toBe([
        '2026-11-08 23:00/2026-11-09 23:00',
        '2026-11-09 23:00/2026-11-10 23:00',
        '2026-11-10 23:00/2026-11-11 23:00',
        '2026-11-11 23:00/2026-11-12 23:00',
        '2026-11-12 23:00/2026-11-13 23:00',
        '2026-11-13 23:00/2026-11-14 17:00',
        '2026-11-14 17:00/2026-11-14 23:00',
        '2026-11-14 23:00/2026-11-15 23:00',
    ]);

    expect($windows[0]->practiceMinutes)->toBe(120)
        ->and($windows[0]->isRaceWeekend())->toBeFalse()
        ->and($windows[5]->practiceMinutes)->toBe(120)
        ->and($windows[6]->practiceMinutes)->toBeNull()
        ->and($windows[6]->qualifyingMinutes)->toBe(30)
        ->and($windows[6]->raceDurationType)->toBe(RaceDurationType::Time)
        ->and($windows[6]->raceMinutes)->toBe(20)
        ->and($windows[6]->raceLaps)->toBeNull()
        ->and(scheduleUtc($windows[0]->weekStartsAt))->toBe('2026-11-08 23:00')
        ->and($windows[0]->key)->toBe('2026-W46:v'.$windows[0]->versionId.':2026-11-08T23:00Z');
});

test('a segment spans midnight until the next segment of the version', function () {
    ScheduleVersion::query()->delete();
    scheduleVersionWith('2026-01-01 00:00', [
        ['weekday' => 1, 'starts_at' => '22:00', 'practice_minutes' => null, 'qualifying_minutes' => 10, 'race_duration_type' => 'laps', 'race_laps' => 3],
        ['weekday' => 3, 'starts_at' => '08:00', 'practice_minutes' => 60],
    ]);

    $windows = scheduleWindowsOf('2026-11-09');

    expect(scheduleSpans($windows))->toBe([
        '2026-11-08 23:00/2026-11-09 21:00',
        '2026-11-09 21:00/2026-11-11 07:00',
        '2026-11-11 07:00/2026-11-15 23:00',
    ])
        ->and($windows[1]->isRaceWeekend())->toBeTrue()
        ->and($windows[1]->raceLaps)->toBe(3)
        ->and($windows[1]->raceDurationType)->toBe(RaceDurationType::Laps);
});

test('a week without a monday midnight segment starts with the last segment of the week', function () {
    ScheduleVersion::query()->delete();
    scheduleVersionWith('2026-01-01 00:00', [
        ['weekday' => 2, 'starts_at' => '10:00', 'practice_minutes' => 30],
        ['weekday' => 6, 'starts_at' => '12:00', 'practice_minutes' => 90],
    ]);

    $windows = scheduleWindowsOf('2026-11-09');

    expect(scheduleSpans($windows))->toBe([
        '2026-11-08 23:00/2026-11-10 09:00',
        '2026-11-10 09:00/2026-11-14 11:00',
        '2026-11-14 11:00/2026-11-15 23:00',
    ])
        ->and($windows[0]->practiceMinutes)->toBe(90)
        ->and($windows[1]->practiceMinutes)->toBe(30)
        ->and($windows[2]->practiceMinutes)->toBe(90);
});

test('a new version cuts the old window and starts with the segment containing that instant', function () {
    $old = ScheduleVersion::query()->sole();
    $new = scheduleVersionWith('2026-11-11 10:00', [
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 60],
        ['weekday' => 3, 'starts_at' => '12:00', 'practice_minutes' => null, 'qualifying_minutes' => 15, 'race_duration_type' => 'laps', 'race_laps' => 5],
    ]);

    $windows = scheduleWindowsOf('2026-11-09');

    expect(scheduleSpans($windows))->toBe([
        '2026-11-08 23:00/2026-11-09 23:00',
        '2026-11-09 23:00/2026-11-10 23:00',
        '2026-11-10 23:00/2026-11-11 09:00',
        '2026-11-11 09:00/2026-11-11 11:00',
        '2026-11-11 11:00/2026-11-15 23:00',
    ])
        ->and(collect($windows)->pluck('versionId')->all())->toBe([$old->id, $old->id, $old->id, $new->id, $new->id])
        ->and($windows[2]->key)->not->toBe($windows[3]->key)
        ->and($windows[2]->practiceMinutes)->toBe(120)
        ->and($windows[3]->practiceMinutes)->toBe(60)
        ->and($windows[4]->qualifyingMinutes)->toBe(15);
});

test('a version effective before the week start replaces the previous one for the whole week', function () {
    $new = scheduleVersionWith('2026-11-05 12:00', [
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 45],
    ]);

    $windows = scheduleWindowsOf('2026-11-09');

    expect($windows)->toHaveCount(1)
        ->and($windows[0]->versionId)->toBe($new->id)
        ->and(scheduleSpans($windows))->toBe(['2026-11-08 23:00/2026-11-15 23:00']);
});

test('daylight saving weeks have no gaps and the expected length', function (string $monday, int $hours) {
    $windows = scheduleWindowsOf($monday);

    $weekStart = scheduleLocal($monday)->setTimezone('UTC');
    $weekEnd = scheduleLocal($monday)->addDays(7)->startOfDay()->setTimezone('UTC');

    expect($windows[0]->startsAt->equalTo($weekStart))->toBeTrue()
        ->and(end($windows)->endsAt->equalTo($weekEnd))->toBeTrue();

    for ($index = 1; $index < count($windows); $index++) {
        expect($windows[$index]->startsAt->equalTo($windows[$index - 1]->endsAt))->toBeTrue();
    }

    $total = array_sum(array_map(fn (ScheduleWindow $w): int => (int) $w->startsAt->diffInMinutes($w->endsAt, true), $windows));

    expect($total)->toBe($hours * 60);
})->with([
    'march' => ['2026-03-23', 167],
    'october' => ['2026-10-19', 169],
]);

test('scheduleWindowAt includes the start and excludes the end', function () {
    $calendar = new WeekCalendar;

    $saturdayEvening = $calendar->scheduleWindowAt(scheduleLocal('2026-11-14 18:00:00'));
    $justBefore = $calendar->scheduleWindowAt(scheduleLocal('2026-11-14 17:59:59'));
    $sundayStart = $calendar->scheduleWindowAt(scheduleLocal('2026-11-15 00:00:00'));
    $mondayStart = $calendar->scheduleWindowAt(scheduleLocal('2026-11-16 00:00:00'));

    expect(scheduleUtc($saturdayEvening->startsAt))->toBe('2026-11-14 17:00')
        ->and($saturdayEvening->isRaceWeekend())->toBeTrue()
        ->and(scheduleUtc($justBefore->endsAt))->toBe('2026-11-14 17:00')
        ->and($justBefore->isRaceWeekend())->toBeFalse()
        ->and(scheduleUtc($sundayStart->startsAt))->toBe('2026-11-14 23:00')
        ->and(scheduleUtc($mondayStart->startsAt))->toBe('2026-11-15 23:00')
        ->and($mondayStart->key)->toContain('2026-W47');
});

test('there must be a version in effect', function () {
    ScheduleVersion::query()->delete();

    (new WeekCalendar)->scheduleWindows(scheduleLocal('2026-11-09'));
})->throws(LogicException::class);

test('a version that starts after the week leaves it without schedule', function () {
    ScheduleVersion::query()->delete();
    scheduleVersionWith('2026-12-01 00:00', [
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 60],
    ]);

    (new WeekCalendar)->scheduleWindowAt(scheduleLocal('2026-11-10 12:00'));
})->throws(LogicException::class);

test('nextRaceWindowStart returns the start of the next race window strictly after the instant', function () {
    $calendar = new WeekCalendar;

    $fromWeekday = $calendar->nextRaceWindowStart(scheduleLocal('2026-11-11 12:00:00'));
    $inside = $calendar->nextRaceWindowStart(scheduleLocal('2026-11-14 18:30:00'));
    $atStart = $calendar->nextRaceWindowStart(scheduleLocal('2026-11-14 18:00:00'));

    expect(scheduleUtc($fromWeekday))->toBe('2026-11-14 17:00')
        ->and(scheduleUtc($inside))->toBe('2026-11-21 17:00')
        ->and(scheduleUtc($atStart))->toBe('2026-11-21 17:00');
});

test('nextRaceWindowStart is null when neither this week nor the next has a race window', function () {
    ScheduleVersion::query()->delete();
    scheduleVersionWith('2026-01-01 00:00', [
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 60],
    ]);

    expect((new WeekCalendar)->nextRaceWindowStart(scheduleLocal('2026-11-11 12:00:00')))->toBeNull();
});

test('a calendar instance loads the schedule once and forgetSchedule drops it', function () {
    $calendar = new WeekCalendar;
    $monday = scheduleLocal('2026-11-09');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $calendar->scheduleWindows($monday);
    $calendar->scheduleWindows($monday);
    $calendar->scheduleWindowAt(scheduleLocal('2026-11-11 12:00'));
    $calendar->nextRaceWindowStart(scheduleLocal('2026-11-11 12:00'));

    $loaded = count(DB::getQueryLog());

    $calendar->forgetSchedule();
    $calendar->scheduleWindows($monday);

    expect($loaded)->toBeLessThanOrEqual(2)
        ->and(count(DB::getQueryLog()))->toBeGreaterThan($loaded);
});
