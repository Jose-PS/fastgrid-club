<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\RaceDurationType;
use App\Enums\TrackMode;
use App\Integration\Server\Data\ConfigPlan;
use App\Models\Car;
use App\Models\Server;
use App\Models\Week;
use App\Support\IngameName;
use App\Support\ScheduleWindow;

class BuildConfigPlan
{
    private const PRACTICE_MODE_QUALIFYING_SECONDS = 1800;

    private const PRACTICE_MODE_RACE_SECONDS = 1200;

    public function __construct(private readonly IngameName $ingameName) {}

    public function handle(Server $server, ?Week $week, ScheduleWindow $window): ?ConfigPlan
    {
        if ($week === null || $week->track === null) {
            return null;
        }

        $carRefs = array_values($week->cars->map(fn (Car $car): string => $car->internal_name)->unique()->sort()->all());

        if ($carRefs === []) {
            return null;
        }

        $isRaceWeekend = $window->isRaceWeekend();

        if (! $isRaceWeekend && ($window->practiceMinutes ?? 0) <= 0) {
            return null;
        }

        $track = $isRaceWeekend ? $week->track : $week->track->pairedTrack;

        if ($track === null) {
            return null;
        }

        $name = $this->ingameName->for($server, $week);
        $maxPlayers = $week->max_players ?? $server->max_players;
        $practiceSeconds = ($window->practiceMinutes ?? 0) * 60;

        if (! $isRaceWeekend) {
            return new ConfigPlan(
                TrackMode::Practice,
                $track->token,
                $carRefs,
                $name,
                $maxPlayers,
                self::PRACTICE_MODE_QUALIFYING_SECONDS,
                self::PRACTICE_MODE_RACE_SECONDS,
                RaceDurationType::Time,
                null,
                $practiceSeconds,
                false,
                $window->startHour,
                $window->weather,
            );
        }

        $laps = $window->raceDurationType === RaceDurationType::Laps;

        return new ConfigPlan(
            TrackMode::RaceWeekend,
            $track->token,
            $carRefs,
            $name,
            $maxPlayers,
            ($window->qualifyingMinutes ?? 0) * 60,
            $laps ? null : ($window->raceMinutes ?? 0) * 60,
            $laps ? RaceDurationType::Laps : RaceDurationType::Time,
            $laps ? $window->raceLaps : null,
            $practiceSeconds,
            true,
            $window->startHour,
            $window->weather,
        );
    }
}
