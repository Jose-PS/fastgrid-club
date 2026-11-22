<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WeekStatus;
use App\Models\Server;
use App\Models\Week;
use App\Support\Translations;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function __invoke(Translations $translations, WeekCalendar $calendar): Response
    {
        $now = CarbonImmutable::now();
        $currentStart = $calendar->weekStartFor($now);
        $nextStart = $calendar->nextWeekStart($currentStart);

        $server = Server::query()->where('is_active', true)->orderBy('id')->first();

        $stored = $server === null
            ? collect()
            : $server->weeks()
                ->with(['track', 'cars'])
                ->whereIn('starts_at', [$currentStart, $nextStart])
                ->get();

        return Inertia::render('calendar', [
            'pageTranslations' => $translations->forGroups(['cal', 'strip', 'week']),
            'serverNow' => $this->iso($calendar, $now),
            'weeks' => [
                $this->serialize('current', $currentStart, $this->storedFor($stored, $currentStart), $calendar, $now),
                $this->serialize('next', $nextStart, $this->storedFor($stored, $nextStart), $calendar, $now),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Week>  $stored
     */
    private function storedFor(Collection $stored, CarbonImmutable $weekStart): ?Week
    {
        return $stored->first(fn (Week $week): bool => $week->starts_at->equalTo($weekStart));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(string $key, CarbonImmutable $weekStart, ?Week $week, WeekCalendar $calendar, CarbonImmutable $now): array
    {
        $configured = $week !== null && $week->track !== null && $week->status !== WeekStatus::Voting;

        return [
            'key' => $key,
            'startsAt' => $this->iso($calendar, $weekStart),
            'endsAt' => $this->iso($calendar, $calendar->nextWeekStart($weekStart)),
            'zone' => $weekStart->setTimezone($calendar->timezone())->format('T'),
            'status' => $week?->status->value,
            'votingClosesAt' => $this->iso($calendar, $week?->voting_closes_at !== null ? CarbonImmutable::instance($week->voting_closes_at) : $calendar->votingClosesAt($weekStart)),
            'configuration' => $configured ? [
                'track' => $week->track->display,
                'cars' => $week->cars->sortBy([['display_name', 'asc'], ['id', 'asc']])->pluck('display_name')->values()->all(),
            ] : null,
            'days' => $calendar->scheduleDays($weekStart, $now),
        ];
    }

    private function iso(WeekCalendar $calendar, CarbonImmutable $instant): string
    {
        return $calendar->iso($instant);
    }
}
