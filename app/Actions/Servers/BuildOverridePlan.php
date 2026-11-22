<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\InitialGrip;
use App\Enums\RaceDurationType;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\MandatoryPitstop;
use App\Integration\Server\Data\SessionSettings;
use App\Models\Car;
use App\Models\Server;
use App\Models\Track;
use Illuminate\Validation\ValidationException;

class BuildOverridePlan
{
    public const PRACTICE_MODE_QUALIFYING_SECONDS = 1800;

    public const PRACTICE_MODE_RACE_SECONDS = 1200;

    public const MANDATORY_PITSTOP_MIN_RACE_SECONDS = 1200;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Server $server, array $input): ConfigPlan
    {
        $mode = TrackMode::from((string) $input['mode']);
        $isRaceWeekend = $mode === TrackMode::RaceWeekend;
        $sessions = (array) ($input['sessions'] ?? []);
        $practice = (array) ($sessions['practice'] ?? []);
        $qualify = (array) ($sessions['qualify'] ?? []);
        $warmup = (array) ($sessions['warmup'] ?? []);
        $race = (array) ($sessions['race'] ?? []);

        $track = $this->usedTrack((int) $input['track_id'], $mode);
        $maxPlayers = (int) $input['max_players'];
        $capacity = $server->capacityFor($track);

        if ($maxPlayers > $capacity) {
            $this->fail('max_players', __('admin.override.errMaxPlayers', ['max' => $capacity]));
        }

        $practiceSeconds = (int) ($practice['length_sec'] ?? 0);

        if (! $isRaceWeekend && $practiceSeconds <= 0) {
            $this->fail('sessions.practice.length_sec', __('admin.override.errPractice'));
        }

        $durationType = RaceDurationType::tryFrom((string) ($race['duration_type'] ?? '')) ?? RaceDurationType::Time;
        $laps = $durationType === RaceDurationType::Laps;
        $raceLength = (int) ($race['length_sec'] ?? 0);
        $raceLaps = isset($race['laps']) ? (int) $race['laps'] : null;

        if ($isRaceWeekend && $laps && ($raceLaps ?? 0) < 1) {
            $this->fail('sessions.race.laps', __('admin.override.errRaceLaps'));
        }

        if ($isRaceWeekend && ! $laps && $raceLength < 1) {
            $this->fail('sessions.race.length_sec', __('admin.override.errRaceLength'));
        }

        return new ConfigPlan(
            $mode,
            $track->token,
            $this->carRefs((array) $input['car_ids']),
            (string) $input['server_name'],
            $maxPlayers,
            $isRaceWeekend ? (int) ($qualify['length_sec'] ?? 0) : self::PRACTICE_MODE_QUALIFYING_SECONDS,
            $isRaceWeekend ? ($laps ? null : $raceLength) : self::PRACTICE_MODE_RACE_SECONDS,
            $isRaceWeekend ? $durationType : RaceDurationType::Time,
            $isRaceWeekend && $laps ? $raceLaps : null,
            $practiceSeconds,
            (bool) $input['cycle_enabled'],
            (int) ($practice['hour'] ?? 14),
            WeatherType::from((string) $input['weather']),
            $isRaceWeekend ? (int) ($warmup['length_sec'] ?? 0) : null,
            ServerType::from((string) $input['server_type']),
            TuningType::from((string) $input['tuning_type']),
            WeatherBehaviour::from((string) $input['weather_behaviour']),
            InitialGrip::from((string) $input['initial_grip']),
            $this->sessionSettings($sessions),
            $isRaceWeekend && isset($race['min_waiting_for_players']) ? (int) $race['min_waiting_for_players'] : null,
            $isRaceWeekend && isset($race['max_waiting_for_players']) ? (int) $race['max_waiting_for_players'] : null,
            $this->pitstop($race, $isRaceWeekend && ! $laps, $raceLength),
        );
    }

    private function usedTrack(int $trackId, TrackMode $mode): Track
    {
        $track = Track::query()->with('pairedTrack')->where('active', true)->where('mode', TrackMode::RaceWeekend)->find($trackId);

        if ($track === null) {
            $this->fail('track_id', __('admin.override.errTrack'));
        }

        if ($mode === TrackMode::RaceWeekend) {
            return $track;
        }

        $paired = $track->pairedTrack;

        if ($paired === null || ! $paired->active) {
            $this->fail('track_id', __('admin.override.errTrackPractice'));
        }

        return $paired;
    }

    /**
     * @param  array<int, mixed>  $carIds
     * @return list<string>
     */
    private function carRefs(array $carIds): array
    {
        $refs = Car::query()
            ->where('active', true)
            ->whereIn('id', $carIds)
            ->pluck('internal_name')
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($refs === [] || count($refs) !== count(array_unique($carIds))) {
            $this->fail('car_ids', __('admin.override.errCars'));
        }

        return array_values($refs);
    }

    /**
     * @param  array<string, mixed>  $sessions
     * @return array<string, SessionSettings>
     */
    private function sessionSettings(array $sessions): array
    {
        $settings = [];

        foreach (ConfigPlan::SESSIONS as $name) {
            $session = $sessions[$name] ?? null;

            if (! is_array($session)) {
                continue;
            }

            $settings[$name] = SessionSettings::fromArray($session);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $race
     */
    private function pitstop(array $race, bool $timedRace, int $raceLength): MandatoryPitstop
    {
        $requested = (bool) ($race['mandatory_pitstop_enabled'] ?? false);
        $eligible = $timedRace && $raceLength > self::MANDATORY_PITSTOP_MIN_RACE_SECONDS;
        $window = (int) ($race['mandatory_pitstop_window_seconds'] ?? 600);

        if ($requested && $eligible && ($window < 1 || $window > $raceLength)) {
            $this->fail('sessions.race.mandatory_pitstop_window_seconds', __('admin.override.errPitWindow', ['max' => $raceLength]));
        }

        return new MandatoryPitstop(
            $requested && $eligible,
            $eligible ? min(max($window, 1), $raceLength) : max($window, 1),
            (bool) ($race['mandatory_pitstop_refuel'] ?? true),
            (bool) ($race['mandatory_pitstop_tyre_change'] ?? true),
        );
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
