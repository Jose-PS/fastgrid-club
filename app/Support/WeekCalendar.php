<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ScheduleSegment;
use App\Models\ScheduleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class WeekCalendar
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @var Collection<int, ScheduleVersion>|null
     */
    private ?Collection $loadedVersions = null;

    /**
     * @var array<string, array<int, ScheduleWindow>>
     */
    private array $windowsByWeek = [];

    public function forgetSchedule(): void
    {
        $this->loadedVersions = null;
        $this->windowsByWeek = [];
    }

    public function timezone(): string
    {
        return (string) config('mvp.timezone');
    }

    public function weekStartFor(CarbonImmutable $instant): CarbonImmutable
    {
        return $this->utc($this->localWeekStart($instant));
    }

    public function nextWeekStart(CarbonImmutable $weekStart): CarbonImmutable
    {
        return $this->utc($this->localWeekStart($weekStart)->addDays(7)->startOfDay());
    }

    public function votingClosesAt(CarbonImmutable $weekStart): CarbonImmutable
    {
        return $this->weekStartFor($weekStart)->subSecond();
    }

    /**
     * @return array<int, ScheduleWindow>
     */
    public function scheduleWindows(CarbonImmutable $weekStart): array
    {
        $localStart = $this->localWeekStart($weekStart);
        $startUtc = $this->utc($localStart);
        $endUtc = $this->utc($localStart->addDays(7)->startOfDay());
        $prefix = $localStart->format('o').'-W'.$localStart->format('W');
        $cacheKey = $startUtc->format('Y-m-d\TH:i:s\Z');

        if (isset($this->windowsByWeek[$cacheKey])) {
            return $this->windowsByWeek[$cacheKey];
        }

        $all = $this->allVersions();

        $active = $all->last(fn (ScheduleVersion $candidate): bool => CarbonImmutable::instance($candidate->effective_from)->lessThanOrEqualTo($startUtc));

        if ($active === null) {
            throw new \LogicException('There is no schedule version in effect.');
        }

        $later = $all->filter(fn (ScheduleVersion $candidate): bool => CarbonImmutable::instance($candidate->effective_from)->greaterThan($startUtc)
            && CarbonImmutable::instance($candidate->effective_from)->lessThan($endUtc));

        $versions = collect([$active])->merge($later)->values();

        $windows = [];
        $cursor = $startUtc;

        while ($cursor->lessThan($endUtc)) {
            $version = $versions->last(fn (ScheduleVersion $candidate): bool => CarbonImmutable::instance($candidate->effective_from)->lessThanOrEqualTo($cursor));

            if ($version === null) {
                throw new \LogicException('There is no schedule version in effect.');
            }

            $nextVersion = $versions->first(fn (ScheduleVersion $candidate): bool => CarbonImmutable::instance($candidate->effective_from)->greaterThan($cursor));

            [$segment, $nextSegmentStart] = $this->segmentAround($version, $localStart, $cursor);

            $end = $endUtc;

            if ($nextSegmentStart !== null && $nextSegmentStart->lessThan($end)) {
                $end = $nextSegmentStart;
            }

            if ($nextVersion !== null) {
                $nextVersionStart = CarbonImmutable::instance($nextVersion->effective_from)->setTimezone('UTC');

                if ($nextVersionStart->lessThan($end)) {
                    $end = $nextVersionStart;
                }
            }

            $windows[] = new ScheduleWindow(
                $startUtc,
                $cursor,
                $end,
                "{$prefix}:v{$version->id}:".$cursor->format('Y-m-d\TH:i\Z'),
                $version->id,
                $segment->practice_minutes,
                $segment->qualifying_minutes,
                $segment->race_duration_type,
                $segment->race_minutes,
                $segment->race_laps,
                $segment->start_hour,
                $segment->weather,
            );

            $cursor = $end;
        }

        return $this->windowsByWeek[$cacheKey] = $windows;
    }

    public function scheduleWindowAt(CarbonImmutable $instant): ScheduleWindow
    {
        foreach ($this->scheduleWindows($this->weekStartFor($instant)) as $window) {
            if ($instant->greaterThanOrEqualTo($window->startsAt) && $instant->lessThan($window->endsAt)) {
                return $window;
            }
        }

        throw new \LogicException('The instant does not belong to any schedule window.');
    }

    public function nextRaceWindowStart(CarbonImmutable $now): ?CarbonImmutable
    {
        $weekStart = $this->weekStartFor($now);

        foreach ([$weekStart, $this->nextWeekStart($weekStart)] as $start) {
            foreach ($this->scheduleWindows($start) as $window) {
                if ($window->isRaceWeekend() && $window->startsAt->greaterThan($now)) {
                    return $window->startsAt;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scheduleDays(CarbonImmutable $weekStart, CarbonImmutable $now): array
    {
        $windows = $this->scheduleWindows($weekStart);
        $localStart = $this->localWeekStart($weekStart);
        $days = [];

        foreach (self::DAY_KEYS as $index => $dayKey) {
            $dayStart = $localStart->addDays($index)->startOfDay();
            $dayEnd = $dayStart->addDay();
            $segments = [];

            foreach ($windows as $window) {
                if ($window->endsAt->lessThanOrEqualTo($dayStart) || $window->startsAt->greaterThanOrEqualTo($dayEnd)) {
                    continue;
                }

                $from = $window->startsAt->greaterThan($dayStart) ? $window->startsAt : $dayStart;
                $to = $window->endsAt->lessThan($dayEnd) ? $window->endsAt : $dayEnd;

                $segments[] = [
                    'startsAt' => $this->iso($from),
                    'endsAt' => $this->iso($to),
                    'isRace' => $window->isRaceWeekend(),
                    'practiceMinutes' => $window->practiceMinutes,
                    'qualifyingMinutes' => $window->qualifyingMinutes,
                    'raceType' => $window->raceDurationType?->value,
                    'raceMinutes' => $window->raceMinutes,
                    'raceLaps' => $window->raceLaps,
                    'status' => match (true) {
                        $now->greaterThanOrEqualTo($to) => 'done',
                        $now->greaterThanOrEqualTo($from) => 'now',
                        default => 'pending',
                    },
                ];
            }

            $days[] = [
                'key' => $dayKey,
                'date' => $this->iso($dayStart),
                'isToday' => $now->greaterThanOrEqualTo($dayStart) && $now->lessThan($dayEnd),
                'isPast' => $now->greaterThanOrEqualTo($dayEnd),
                'hasRace' => collect($segments)->contains('isRace', true),
                'segments' => $segments,
            ];
        }

        return $days;
    }

    public function iso(CarbonImmutable $instant): string
    {
        return $instant->setTimezone($this->timezone())->format('c');
    }

    /**
     * @return array{0: ScheduleSegment, 1: CarbonImmutable|null}
     */
    private function segmentAround(ScheduleVersion $version, CarbonImmutable $localWeekStart, CarbonImmutable $cursor): array
    {
        $occurrences = [];

        foreach ([-1, 0, 1] as $offset) {
            $base = $localWeekStart->addDays(7 * $offset)->startOfDay();

            foreach ($version->segments as $segment) {
                [$hour, $minute, $second] = array_map('intval', array_pad(explode(':', $segment->starts_at), 3, '0'));

                $occurrences[] = [
                    $this->utc($base->addDays($segment->weekday - 1)->startOfDay()->setTime($hour, $minute, $second)),
                    $segment,
                ];
            }
        }

        usort($occurrences, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $current = null;
        $next = null;

        foreach ($occurrences as [$startsAt, $segment]) {
            if ($startsAt->lessThanOrEqualTo($cursor)) {
                $current = $segment;

                continue;
            }

            $next = $startsAt;

            break;
        }

        if ($current === null) {
            throw new \LogicException('The schedule version has no segments.');
        }

        return [$current, $next];
    }

    /**
     * @return Collection<int, ScheduleVersion>
     */
    private function allVersions(): Collection
    {
        if ($this->loadedVersions !== null) {
            return $this->loadedVersions;
        }

        $versions = ScheduleVersion::query()
            ->with('segments')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->toBase();

        if ($versions->isNotEmpty()) {
            $this->loadedVersions = $versions;
        }

        return $versions;
    }

    private function localWeekStart(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone($this->timezone())->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    private function utc(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone('UTC');
    }
}
