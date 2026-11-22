<?php

declare(strict_types=1);

use App\Enums\WeekStatus;
use App\Models\Car;
use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use App\Models\Server;
use App\Models\Track;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function calendarProps(): array
{
    return test()->get(route('calendar'))->assertOk()->inertiaProps();
}

function calendarPageTranslations(): array
{
    return test()->get(route('calendar'))->inertiaProps()['pageTranslations'];
}

function madridTravel(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Europe/Madrid'));
}

test('it exposes the current and next week with seven days each', function () {
    madridTravel('2026-11-04 12:00:00');

    $props = calendarProps();

    expect($props['weeks'])->toHaveCount(2)
        ->and($props['weeks'][0]['key'])->toBe('current')
        ->and($props['weeks'][1]['key'])->toBe('next')
        ->and($props['weeks'][0]['startsAt'])->toBe('2026-11-02T00:00:00+01:00')
        ->and($props['weeks'][1]['startsAt'])->toBe('2026-11-09T00:00:00+01:00')
        ->and($props['weeks'][0]['days'])->toHaveCount(7)
        ->and(collect($props['weeks'][0]['days'])->pluck('key')->all())->toBe(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
        ->and(collect($props['weeks'][0]['days'])->where('isToday', true)->pluck('key')->all())->toBe(['wed'])
        ->and(collect($props['weeks'][1]['days'])->where('isToday', true))->toBeEmpty();
});

test('the seeded schedule lists the saturday practice and race windows with their sessions', function () {
    madridTravel('2026-11-04 12:00:00');

    $days = calendarProps()['weeks'][0]['days'];

    expect($days[5]['hasRace'])->toBeTrue()
        ->and($days[5]['segments'])->toHaveCount(2)
        ->and($days[5]['segments'][0])->toMatchArray([
            'startsAt' => '2026-11-07T00:00:00+01:00',
            'endsAt' => '2026-11-07T18:00:00+01:00',
            'isRace' => false,
            'practiceMinutes' => 120,
            'qualifyingMinutes' => null,
            'raceType' => null,
        ])
        ->and($days[5]['segments'][1])->toMatchArray([
            'startsAt' => '2026-11-07T18:00:00+01:00',
            'endsAt' => '2026-11-08T00:00:00+01:00',
            'isRace' => true,
            'practiceMinutes' => null,
            'qualifyingMinutes' => 30,
            'raceType' => 'time',
            'raceMinutes' => 20,
            'raceLaps' => null,
        ])
        ->and($days[0]['hasRace'])->toBeFalse()
        ->and($days[0]['segments'])->toHaveCount(1)
        ->and($days[0]['segments'][0]['startsAt'])->toBe('2026-11-02T00:00:00+01:00')
        ->and($days[0]['segments'][0]['endsAt'])->toBe('2026-11-03T00:00:00+01:00')
        ->and($days[6]['segments'])->toHaveCount(1);
});

test('a window crossing midnight is split between both days and lap races are exposed', function () {
    madridTravel('2026-11-04 12:00:00');
    $version = ScheduleVersion::factory()->create(['effective_from' => CarbonImmutable::parse('2026-11-01 00:00', 'Europe/Madrid')->setTimezone('UTC')]);
    foreach ([
        ['weekday' => 1, 'starts_at' => '00:00', 'practice_minutes' => 60],
        ['weekday' => 1, 'starts_at' => '22:00', 'practice_minutes' => 15, 'qualifying_minutes' => 30, 'race_duration_type' => 'laps', 'race_laps' => 10],
        ['weekday' => 2, 'starts_at' => '02:00', 'practice_minutes' => 60],
    ] as $segment) {
        ScheduleSegment::factory()->create(array_merge(['schedule_version_id' => $version->id], $segment));
    }

    $days = calendarProps()['weeks'][0]['days'];

    expect(collect($days[0]['segments'])->map(fn ($s) => substr($s['startsAt'], 11, 5).'-'.substr($s['endsAt'], 11, 5))->all())->toBe(['00:00-22:00', '22:00-00:00'])
        ->and($days[0]['segments'][1])->toMatchArray([
            'isRace' => true,
            'practiceMinutes' => 15,
            'qualifyingMinutes' => 30,
            'raceType' => 'laps',
            'raceLaps' => 10,
            'raceMinutes' => null,
        ])
        ->and($days[0]['segments'][1]['endsAt'])->toBe('2026-11-03T00:00:00+01:00')
        ->and(collect($days[1]['segments'])->map(fn ($s) => substr($s['startsAt'], 11, 5).'-'.substr($s['endsAt'], 11, 5))->all())->toBe(['00:00-02:00', '02:00-00:00'])
        ->and($days[1]['segments'][0]['isRace'])->toBeTrue()
        ->and($days[1]['segments'][0]['raceLaps'])->toBe(10)
        ->and($days[1]['hasRace'])->toBeTrue();
});

test('it keeps Madrid wall clock hours and the right zone across daylight saving changes', function (string $moment, string $saturday, string $zone, string $offset) {
    madridTravel($moment);

    $week = calendarProps()['weeks'][0];
    $race = $week['days'][5]['segments'][1];

    expect($week['zone'])->toBe($zone)
        ->and($race['startsAt'])->toBe("{$saturday}T18:00:00{$offset}")
        ->and($race['endsAt'])->toBe(CarbonImmutable::parse("{$saturday} 23:00", 'Europe/Madrid')->addHour()->format('c'));
})->with([
    'spring change' => ['2027-03-24 12:00:00', '2027-03-27', 'CET', '+01:00'],
    'autumn change' => ['2026-10-21 12:00:00', '2026-10-24', 'CEST', '+02:00'],
    'after autumn change' => ['2026-10-28 12:00:00', '2026-10-31', 'CET', '+01:00'],
    'summer' => ['2026-07-08 12:00:00', '2026-07-11', 'CEST', '+02:00'],
]);

test('it exposes the next week zone independently from the current one', function () {
    madridTravel('2026-10-21 12:00:00');

    $weeks = calendarProps()['weeks'];

    expect($weeks[0]['zone'])->toBe('CEST')
        ->and($weeks[1]['zone'])->toBe('CET')
        ->and($weeks[1]['days'][5]['segments'][1]['startsAt'])->toBe('2026-10-31T18:00:00+01:00');
});

test('it computes segment status from the server clock', function () {
    madridTravel('2026-11-07 19:15:00');

    $days = calendarProps()['weeks'][0]['days'];

    expect(collect($days[5]['segments'])->pluck('status')->all())->toBe(['done', 'now'])
        ->and($days[4]['segments'][0]['status'])->toBe('done')
        ->and($days[6]['segments'][0]['status'])->toBe('pending');
});

test('every segment of the next week is pending', function () {
    madridTravel('2026-11-07 19:15:00');

    $statuses = collect(calendarProps()['weeks'][1]['days'])->flatMap(fn ($day) => collect($day['segments'])->pluck('status'))->unique()->all();

    expect($statuses)->toBe(['pending']);
});

test('it marks today, past days and race days', function () {
    madridTravel('2026-11-07 19:15:00');

    $days = calendarProps()['weeks'][0]['days'];

    expect($days[4]['isPast'])->toBeTrue()
        ->and($days[5]['isToday'])->toBeTrue()
        ->and($days[5]['hasRace'])->toBeTrue()
        ->and($days[6]['isToday'])->toBeFalse()
        ->and($days[6]['hasRace'])->toBeFalse();
});

test('a next week in voting has no configuration and the real voting deadline', function () {
    madridTravel('2026-11-04 12:00:00');
    $server = Server::factory()->create();
    Week::factory()->for($server)->startingAt(CarbonImmutable::now())->status(WeekStatus::Active)->create();
    Week::factory()->for($server)->startingAt(CarbonImmutable::now()->addWeek())->status(WeekStatus::Voting)->create();

    $next = calendarProps()['weeks'][1];

    expect($next['status'])->toBe('voting')
        ->and($next['configuration'])->toBeNull()
        ->and($next['votingClosesAt'])->toBe('2026-11-08T23:59:59+01:00');
});

test('a scheduled week exposes its track and cars', function () {
    madridTravel('2026-11-04 12:00:00');
    $server = Server::factory()->create();
    $track = Track::factory()->create(['display' => 'Imola GP']);
    $cars = Car::factory()->count(2)->sequence(['display_name' => 'Car A'], ['display_name' => 'Car B'])->create();
    $week = Week::factory()->for($server)->startingAt(CarbonImmutable::now()->addWeek())->status(WeekStatus::Scheduled)->create(['track_id' => $track->id]);
    $week->cars()->attach($cars->pluck('id'));

    $next = calendarProps()['weeks'][1];

    expect(calendarPageTranslations())->toHaveKeys(['week.circuit', 'week.cars'])
        ->and($next['status'])->toBe('scheduled')
        ->and($next['configuration']['track'])->toBe('Imola GP')
        ->and($next['configuration']['cars'])->toEqualCanonicalizing(['Car A', 'Car B']);
});

test('it still renders the schedule when there are no servers or weeks', function () {
    madridTravel('2026-11-04 12:00:00');

    $props = calendarProps();

    expect($props['weeks'][0]['status'])->toBeNull()
        ->and($props['weeks'][1]['status'])->toBeNull()
        ->and($props['weeks'][1]['configuration'])->toBeNull()
        ->and($props['weeks'][1]['votingClosesAt'])->toBe('2026-11-08T23:59:59+01:00')
        ->and($props['weeks'][0]['days'][5]['segments'])->toHaveCount(2);
});

test('it shares the calendar and strip translations', function () {
    $translations = calendarProps()['pageTranslations'];

    expect($translations)->toHaveKeys(['cal.title', 'cal.pending', 'strip.dayNames.sun', 'cal.sessQualy', 'cal.sessRaceLaps', 'cal.loop']);
});

test('the calendar query count does not grow with the data', function () {
    madridTravel('2026-11-04 12:00:00');
    $server = Server::factory()->create();
    $cars = Car::factory()->count(3)->create();
    foreach ([0, 1] as $offset) {
        $week = Week::factory()->for($server)->startingAt(CarbonImmutable::now()->addWeeks($offset))->status(WeekStatus::Scheduled)->create(['track_id' => Track::factory()->create()->id]);
        $week->cars()->attach($cars->pluck('id'));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('calendar'))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(10);
});

test('an active current week without configuration is announced as to be announced, not as voting', function () {
    madridTravel('2026-11-04 12:00:00');
    $server = Server::factory()->create();
    Week::factory()->for($server)->startingAt(CarbonImmutable::now())->status(WeekStatus::Active)->create();

    $current = calendarProps()['weeks'][0];

    expect($current['status'])->toBe('active')
        ->and($current['configuration'])->toBeNull()
        ->and(calendarPageTranslations())->toHaveKeys(['cal.tba', 'cal.pendingCfg']);
});
