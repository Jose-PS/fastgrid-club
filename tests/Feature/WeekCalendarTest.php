<?php

declare(strict_types=1);

use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

function madrid(string $datetime): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, 'Europe/Madrid');
}

function utcString(CarbonImmutable $instant): string
{
    return $instant->setTimezone('UTC')->format('Y-m-d H:i:s');
}

test('daylight saving weeks keep their start, end and voting close', function (string $anchor, string $start, string $end, string $closes, string $nextCloses) {
    $calendar = new WeekCalendar;
    $weekStart = $calendar->weekStartFor(madrid($anchor));

    expect(utcString($weekStart))->toBe($start)
        ->and(utcString($calendar->nextWeekStart($weekStart)))->toBe($end)
        ->and(utcString($calendar->votingClosesAt($weekStart)))->toBe($closes)
        ->and(utcString($calendar->votingClosesAt($calendar->nextWeekStart($weekStart))))->toBe($nextCloses);
})->with([
    'week before autumn change, 25 hour sunday' => ['2026-10-21 12:00:00', '2026-10-18 22:00:00', '2026-10-25 23:00:00', '2026-10-18 21:59:59', '2026-10-25 22:59:59'],
    'week after autumn change' => ['2026-10-28 12:00:00', '2026-10-25 23:00:00', '2026-11-01 23:00:00', '2026-10-25 22:59:59', '2026-11-01 22:59:59'],
    'week with spring change, 23 hour sunday' => ['2027-03-24 12:00:00', '2027-03-21 23:00:00', '2027-03-28 22:00:00', '2027-03-21 22:59:59', '2027-03-28 21:59:59'],
]);

test('weeks have no race format columns', function () {
    foreach (Schema::getColumnListing('weeks') as $column) {
        expect($column)->not->toMatch('/format|laps|minutes|duration/');
    }

    foreach (Schema::getColumnListing('week_cars') as $column) {
        expect($column)->not->toMatch('/format|laps|minutes|duration/');
    }
});
