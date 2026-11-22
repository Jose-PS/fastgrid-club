<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\WeekStatus;
use App\Models\Server;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class StructuredData
{
    private const UPCOMING_DAYS = 7;

    private const MAX_EVENTS = 20;

    public function __construct(
        private readonly PageMeta $pageMeta,
        private readonly WeekCalendar $calendar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function home(string $locale): array
    {
        $homeUrl = $this->pageMeta->absoluteUrl('home', $locale);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebSite',
                    '@id' => $homeUrl.'#website',
                    'name' => $this->pageMeta->appName(),
                    'url' => $homeUrl,
                    'inLanguage' => $locale,
                ],
                $this->organization($locale),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function calendar(string $locale, CarbonImmutable $now): ?array
    {
        $events = $this->raceEvents($locale, $now);

        if ($events === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $events,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organization(string $locale): array
    {
        return [
            '@type' => 'Organization',
            '@id' => $this->organizationId($locale),
            'name' => $this->pageMeta->appName(),
            'url' => $this->pageMeta->absoluteUrl('home', $locale),
            'logo' => rtrim(config()->string('app.url'), '/').'/apple-touch-icon.png',
            'sameAs' => array_values(Arr::except(config()->array('mvp.brand.social'), ['discord', 'github'])),
        ];
    }

    private function organizationId(string $locale): string
    {
        return $this->pageMeta->absoluteUrl('home', $locale).'#organization';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function raceEvents(string $locale, CarbonImmutable $now): array
    {
        $limit = $now->addDays(self::UPCOMING_DAYS);
        $currentStart = $this->calendar->weekStartFor($now);
        $weekStarts = [$currentStart, $this->calendar->nextWeekStart($currentStart)];
        $tracks = $this->trackNames($weekStarts);
        $canonical = $this->pageMeta->absoluteUrl('calendar', $locale);
        $events = [];

        foreach ($weekStarts as $weekStart) {
            foreach ($this->calendar->scheduleWindows($weekStart) as $window) {
                if (! $window->isRaceWeekend() || $window->endsAt->lessThanOrEqualTo($now) || $window->startsAt->greaterThanOrEqualTo($limit)) {
                    continue;
                }

                $events[] = $this->event($window, $tracks[$weekStart->getTimestamp()] ?? null, $locale, $canonical);

                if (count($events) >= self::MAX_EVENTS) {
                    return $events;
                }
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function event(ScheduleWindow $window, ?string $track, string $locale, string $canonical): array
    {
        $appName = $this->pageMeta->appName();

        return [
            '@type' => 'SportsEvent',
            'name' => $track === null
                ? __('meta.event.name', ['app' => $appName], $locale)
                : __('meta.event.nameTrack', ['app' => $appName, 'track' => $track], $locale),
            'startDate' => $this->calendar->iso($window->startsAt),
            'endDate' => $this->calendar->iso($window->endsAt),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'location' => [
                '@type' => 'VirtualLocation',
                'url' => $canonical,
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => $appName,
                'url' => $this->pageMeta->absoluteUrl('home', $locale),
            ],
            'sport' => 'Sim racing',
            'inLanguage' => $locale,
        ];
    }

    /**
     * @param  array<int, CarbonImmutable>  $weekStarts
     * @return array<int, string>
     */
    private function trackNames(array $weekStarts): array
    {
        $server = Server::query()->where('is_active', true)->orderBy('id')->first();

        if ($server === null) {
            return [];
        }

        $names = [];

        $server->weeks()
            ->with('track')
            ->whereIn('starts_at', $weekStarts)
            ->get()
            ->each(function (Week $week) use (&$names): void {
                if ($week->track !== null && $week->status !== WeekStatus::Voting) {
                    $names[$week->starts_at->getTimestamp()] = $week->track->display;
                }
            });

        return $names;
    }
}
