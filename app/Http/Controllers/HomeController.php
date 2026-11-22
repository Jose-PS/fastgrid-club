<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Donations\DonationPayload;
use App\Support\HomeStats;
use App\Support\PublicStatus;
use App\Support\TrackSvg;
use App\Support\Translations;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, HomeStats $homeStats, Translations $translations, WeekCalendar $calendar, PublicStatus $publicStatus, TrackSvg $trackSvg, DonationPayload $donationPayload): Response
    {
        $now = CarbonImmutable::now();
        $weekStart = $calendar->weekStartFor($now);
        $server = $publicStatus->activeServer();
        $week = $publicStatus->currentWeek($server, $now, withCars: true);
        $configured = $publicStatus->isConfigured($week);
        $nextRaceStart = $calendar->nextRaceWindowStart($now);
        $raceInProgress = $calendar->scheduleWindowAt($now)->isRaceWeekend();
        $donation = $donationPayload->get();
        $viewer = $request->user();

        return Inertia::render('welcome', [
            'pageTranslations' => $translations->forGroups(['hero', 'status', 'week', 'strip', 'cal', 'lb', 'res', 'donate']),
            'serverNow' => $calendar->iso($now),
            'status' => $publicStatus->live($server, $week, $now),
            'donation' => $donation['visible'] ? $donation : null,
            'bestLaps' => fn () => $homeStats->bestLaps(
                $this->optionalInteger($request, 'lb_track'),
                $this->optionalInteger($request, 'lb_car'),
                $viewer,
                max(1, $request->integer('lb_page', 1)),
            ),
            'lastRaceDay' => fn () => $homeStats->lastRaceDay(
                $this->optionalInteger($request, 'res_heat'),
                $viewer,
                max(1, $request->integer('res_page', 1)),
            ),
            'trackSvg' => $configured ? $trackSvg->forTrack($week->track) : null,
            'week' => [
                'startsAt' => $calendar->iso($weekStart),
                'endsAt' => $calendar->iso($calendar->nextWeekStart($weekStart)),
                'zone' => $weekStart->setTimezone($calendar->timezone())->format('T'),
                'days' => $calendar->scheduleDays($weekStart, $now),
                'configuration' => $configured ? [
                    'track' => $week->track->display,
                    'lengthM' => $week->track->length_m,
                    'cars' => $week->cars->sortBy([['display_name', 'asc'], ['id', 'asc']])->pluck('display_name')->values()->all(),
                ] : null,
                'raceInProgress' => $raceInProgress,
                'nextRaceStart' => $nextRaceStart === null ? null : $calendar->iso($nextRaceStart),
            ],
        ]);
    }

    private function optionalInteger(Request $request, string $key): ?int
    {
        $value = $request->integer($key);

        return $value > 0 ? $value : null;
    }
}
