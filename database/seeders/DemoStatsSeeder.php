<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Stats\LogResults;
use App\Actions\Stats\RebuildBestLaps;
use App\Enums\TrackMode;
use App\Enums\WeekStatus;
use App\Integration\Server\Log\SessionType;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Track;
use App\Models\Week;
use App\Support\ScheduleWindow;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoStatsSeeder extends Seeder
{
    private const DRIVER_COUNT = 60;

    private const RACE_DRIVERS = 25;

    private const RACE_HEATS = 6;

    private const WEEKS_LOOKBACK = 8;

    private const DEMO_QUALIFYING_MINUTES = 30;

    private const DEMO_RACE_MINUTES = 20;

    private const STEAM_PREFIX = '76561190000';

    private const HASH_PREFIX = 'demo-';

    private const INSERT_CHUNK = 500;

    public function run(WeekCalendar $calendar, LogResults $results, RebuildBestLaps $rebuild): void
    {
        $this->clean();

        $server = Server::query()->where('slug', 'main')->first()
            ?? Server::query()->orderBy('id')->first()
            ?? Server::factory()->create(['slug' => 'main']);

        $now = CarbonImmutable::now();
        $week = $this->week($server, $calendar, $now);
        $cars = $this->cars($week);
        $drivers = $this->drivers();

        DB::transaction(function () use ($server, $calendar, $results, $rebuild, $now, $week, $cars, $drivers): void {
            $rows = $this->practiceLaps($server, $week, $cars, $drivers, $now);

            $window = $this->lastRaceWindow($calendar, $now);
            $saturday = $this->raceWeek($server, $week, $calendar, $window);
            $qualifyingMinutes = $window->qualifyingMinutes ?? self::DEMO_QUALIFYING_MINUTES;
            $raceMinutes = $window->raceMinutes ?? self::DEMO_RACE_MINUTES;
            $races = [];

            for ($index = 0; $index < self::RACE_HEATS; $index++) {
                $startsAt = $window->startsAt->addMinutes($index * ($qualifyingMinutes + $raceMinutes));
                $qualifyingEndsAt = $startsAt->addMinutes($qualifyingMinutes);
                $raceEndsAt = $qualifyingEndsAt->addMinutes($raceMinutes);

                if ($raceEndsAt->greaterThan($window->endsAt)) {
                    break;
                }

                $qualifying = $this->session($server, $saturday, $index, SessionType::Qualifying, $startsAt, $qualifyingEndsAt);
                $race = $this->session($server, $saturday, $index, SessionType::Race, $qualifyingEndsAt, $raceEndsAt);
                $races[] = $race;

                $field = $drivers->shuffle()->take(self::RACE_DRIVERS)->values();
                $assigned = $field->mapWithKeys(fn (Driver $driver): array => [$driver->id => $cars->random()]);

                $rows = array_merge(
                    $rows,
                    $this->qualifyingLaps($server, $qualifying, $saturday, $field, $assigned, $startsAt),
                    $this->raceLaps($server, $race, $saturday, $field, $assigned, $qualifyingEndsAt),
                );
            }

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                Lap::query()->insert($chunk);
            }

            foreach ($races as $race) {
                $results->rebuild($race);
            }

            $rebuild->handle();
        });

        $this->command?->info("Demo stats seeded for the week of {$week->starts_at->toDateString()} on {$week->track?->display}.");
    }

    private function clean(): void
    {
        ServerSession::query()->where('source_line_hash', 'like', self::HASH_PREFIX.'%')->delete();
        Driver::query()->where('steam_id', 'like', self::STEAM_PREFIX.'%')->delete();
    }

    private function week(Server $server, WeekCalendar $calendar, CarbonImmutable $now): Week
    {
        $week = $server->weeks()->with(['track', 'cars'])->where('starts_at', $calendar->weekStartFor($now))->first();

        if ($week === null || $week->track === null) {
            $week = $server->weeks()->with(['track', 'cars'])->whereNotNull('track_id')->orderByDesc('starts_at')->first();

            if ($week !== null) {
                $this->command?->warn('No configured week for the current period; using the latest configured week.');
            }
        }

        if ($week !== null) {
            return $week;
        }

        $this->command?->warn('No configured week found; creating one with the first race weekend circuit and cars of the catalog.');

        $track = Track::query()->where('mode', TrackMode::RaceWeekend)->where('active', true)->orderBy('id')->first()
            ?? Track::factory()->withPracticePair()->create();

        $start = $calendar->weekStartFor($now);

        $week = Week::query()->create([
            'server_id' => $server->id,
            'status' => WeekStatus::Active,
            'starts_at' => $start,
            'ends_at' => $calendar->nextWeekStart($start),
            'voting_closes_at' => $calendar->votingClosesAt($start),
            'track_id' => $track->id,
        ]);

        $catalogCars = Car::query()->where('active', true)->orderBy('id')->limit(3)->get();
        $week->cars()->attach(($catalogCars->isEmpty() ? Car::factory()->count(3)->create() : $catalogCars)->pluck('id'));

        return $week->load(['track', 'cars']);
    }

    /**
     * @return Collection<int, Car>
     */
    private function cars(Week $week): Collection
    {
        return $week->cars->isNotEmpty()
            ? $week->cars->values()
            : Car::query()->where('active', true)->orderBy('id')->limit(3)->get();
    }

    private function lastRaceWindow(WeekCalendar $calendar, CarbonImmutable $now): ScheduleWindow
    {
        $weekStart = $calendar->weekStartFor($now);

        for ($weeksBack = 0; $weeksBack < self::WEEKS_LOOKBACK; $weeksBack++) {
            $windows = array_filter(
                $calendar->scheduleWindows($weekStart),
                fn (ScheduleWindow $window): bool => $window->isRaceWeekend() && $window->endsAt->lessThanOrEqualTo($now),
            );

            if ($windows !== []) {
                return end($windows);
            }

            $weekStart = $calendar->weekStartFor($weekStart->subDay());
        }

        throw new RuntimeException('There is no completed race window in the latest weeks.');
    }

    private function raceWeek(Server $server, Week $week, WeekCalendar $calendar, ScheduleWindow $window): Week
    {
        $start = $window->weekStartsAt;

        $raceWeek = Week::query()->firstOrCreate(
            ['server_id' => $server->id, 'starts_at' => $start],
            [
                'status' => WeekStatus::Finished,
                'ends_at' => $calendar->nextWeekStart($start),
                'voting_closes_at' => $calendar->votingClosesAt($start),
                'track_id' => $week->track_id,
            ],
        );

        if ($raceWeek->wasRecentlyCreated) {
            $raceWeek->cars()->attach($this->cars($week)->pluck('id'));
        }

        return $raceWeek;
    }

    /**
     * @return Collection<int, Driver>
     */
    private function drivers(): Collection
    {
        $drivers = new Collection;

        for ($number = 1; $number <= self::DRIVER_COUNT; $number++) {
            $drivers->push(Driver::query()->create([
                'steam_id' => self::STEAM_PREFIX.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                'last_name' => fake()->unique()->userName(),
                'first_seen_at' => now()->subDays(20),
                'last_seen_at' => now(),
            ]));
        }

        return $drivers;
    }

    private function session(Server $server, Week $week, int $index, SessionType $type, CarbonImmutable $startedAt, CarbonImmutable $endedAt): ServerSession
    {
        return ServerSession::query()->create([
            'server_id' => $server->id,
            'type' => $type,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'track_id' => $week->track_id,
            'week_id' => $week->id,
            'cycle_index' => $index,
            'source_line_hash' => self::HASH_PREFIX."{$week->id}-{$index}-{$type->value}",
        ]);
    }

    /**
     * @param  Collection<int, Car>  $cars
     * @param  Collection<int, Driver>  $drivers
     * @return list<array<string, mixed>>
     */
    private function practiceLaps(Server $server, Week $week, Collection $cars, Collection $drivers, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($drivers as $driver) {
            foreach ($cars as $carIndex => $car) {
                for ($lap = 0; $lap < 4; $lap++) {
                    $rows[] = $this->lapRow(
                        $server,
                        null,
                        $driver,
                        $car,
                        $week,
                        $this->baseMs($carIndex, $driver) + random_int(0, 4000),
                        null,
                        $now->subMinutes(random_int(60, 60 * 24 * 10)),
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Driver>  $field
     * @param  Collection<int, Car>  $assigned
     * @return list<array<string, mixed>>
     */
    private function qualifyingLaps(Server $server, ServerSession $session, Week $week, Collection $field, Collection $assigned, CarbonImmutable $startsAt): array
    {
        $rows = [];

        foreach ($field as $driver) {
            $car = $assigned[$driver->id];

            for ($lap = 0; $lap < 3; $lap++) {
                $rows[] = $this->lapRow(
                    $server,
                    $session,
                    $driver,
                    $car,
                    $week,
                    $this->baseMs((int) $car->id % 3, $driver) + random_int(0, 1500),
                    null,
                    $startsAt->addMinutes(3 + $lap * 8)->addSeconds(random_int(0, 60)),
                );
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Driver>  $field
     * @param  Collection<int, Car>  $assigned
     * @return list<array<string, mixed>>
     */
    private function raceLaps(Server $server, ServerSession $session, Week $week, Collection $field, Collection $assigned, CarbonImmutable $startsAt): array
    {
        $rows = [];

        foreach ($field as $position => $driver) {
            $car = $assigned[$driver->id];

            $count = match (true) {
                $position < 18 => 12,
                $position < 22 => 11,
                default => random_int(3, 6),
            };

            $elapsed = random_int(0, 3000);

            for ($lap = 1; $lap <= $count; $lap++) {
                $ms = $this->baseMs((int) $car->id % 3, $driver) + random_int(200, 2500) + ($lap === 1 ? 4000 : 0);
                $elapsed += $ms;

                $rows[] = $this->lapRow($server, $session, $driver, $car, $week, $ms, $lap, $startsAt->addMilliseconds($elapsed + 8000));
            }
        }

        return $rows;
    }

    private function baseMs(int $carIndex, Driver $driver): int
    {
        return 90000 + $carIndex * 2000 + (($driver->id * 37) % 60) * 100;
    }

    /**
     * @return array<string, mixed>
     */
    private function lapRow(Server $server, ?ServerSession $session, Driver $driver, Car $car, Week $week, int $ms, ?int $number, CarbonImmutable $at): array
    {
        return [
            'server_id' => $server->id,
            'server_session_id' => $session?->id,
            'driver_id' => $driver->id,
            'car_id' => $car->id,
            'track_id' => $week->track_id,
            'lap_ms' => $ms,
            'lap_number' => $number,
            'is_valid' => true,
            'recorded_at' => $at->utc()->format('Y-m-d H:i:s.vP'),
            'source_hash' => hash('sha256', self::HASH_PREFIX.$driver->id.'-'.($session->id ?? 'p').'-'.$car->id.'-'.($number ?? $at->format('U.u')).'-'.$ms),
        ];
    }
}
