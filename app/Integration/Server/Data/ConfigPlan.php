<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

use App\Enums\InitialGrip;
use App\Enums\RaceDurationType;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;

final readonly class ConfigPlan
{
    public const SESSIONS = ['practice', 'qualify', 'warmup', 'race'];

    /**
     * @param  list<string>  $carRefs
     * @param  array<string, SessionSettings>  $sessionSettings
     */
    public function __construct(
        public TrackMode $mode,
        public string $trackRef,
        public array $carRefs,
        public string $serverName,
        public int $maxPlayers,
        public int $qualifyingSeconds,
        public ?int $raceSeconds,
        public RaceDurationType $raceDurationType,
        public ?int $raceLaps,
        public ?int $practiceSeconds,
        public bool $cycleEnabled,
        public int $startHour,
        public WeatherType $weather,
        public ?int $warmupSeconds = null,
        public ?ServerType $serverType = null,
        public ?TuningType $tuningType = null,
        public ?WeatherBehaviour $weatherBehaviour = null,
        public ?InitialGrip $initialGrip = null,
        public array $sessionSettings = [],
        public ?int $minWaitingForPlayers = null,
        public ?int $maxWaitingForPlayers = null,
        public ?MandatoryPitstop $mandatoryPitstop = null,
    ) {}

    public function withMaxPlayers(int $maxPlayers): self
    {
        return new self(...[...get_object_vars($this), 'maxPlayers' => $maxPlayers]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'mode' => $this->mode->value,
            'track_ref' => $this->trackRef,
            'car_refs' => $this->carRefs,
            'server_name' => $this->serverName,
            'max_players' => $this->maxPlayers,
            'qualifying_seconds' => $this->qualifyingSeconds,
            'race_seconds' => $this->raceSeconds,
            'race_duration_type' => $this->raceDurationType->value,
            'race_laps' => $this->raceLaps,
            'practice_seconds' => $this->practiceSeconds,
            'cycle_enabled' => $this->cycleEnabled,
            'start_hour' => $this->startHour,
            'weather' => $this->weather->value,
        ];

        $optional = [
            'warmup_seconds' => $this->warmupSeconds,
            'server_type' => $this->serverType?->value,
            'tuning_type' => $this->tuningType?->value,
            'weather_behaviour' => $this->weatherBehaviour?->value,
            'initial_grip' => $this->initialGrip?->value,
            'min_waiting_for_players' => $this->minWaitingForPlayers,
            'max_waiting_for_players' => $this->maxWaitingForPlayers,
            'mandatory_pitstop' => $this->mandatoryPitstop?->toArray(),
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        if ($this->sessionSettings !== []) {
            $data['session_settings'] = array_map(fn (SessionSettings $settings): array => $settings->toArray(), $this->sessionSettings);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $sessions = [];

        foreach ((array) ($data['session_settings'] ?? []) as $name => $settings) {
            $sessions[(string) $name] = SessionSettings::fromArray((array) $settings);
        }

        return new self(
            TrackMode::from((string) $data['mode']),
            (string) $data['track_ref'],
            array_values(array_map('strval', (array) $data['car_refs'])),
            (string) $data['server_name'],
            (int) $data['max_players'],
            (int) $data['qualifying_seconds'],
            isset($data['race_seconds']) ? (int) $data['race_seconds'] : null,
            RaceDurationType::from((string) $data['race_duration_type']),
            isset($data['race_laps']) ? (int) $data['race_laps'] : null,
            isset($data['practice_seconds']) ? (int) $data['practice_seconds'] : null,
            (bool) $data['cycle_enabled'],
            (int) $data['start_hour'],
            WeatherType::from((string) $data['weather']),
            isset($data['warmup_seconds']) ? (int) $data['warmup_seconds'] : null,
            isset($data['server_type']) ? ServerType::from((string) $data['server_type']) : null,
            isset($data['tuning_type']) ? TuningType::from((string) $data['tuning_type']) : null,
            isset($data['weather_behaviour']) ? WeatherBehaviour::from((string) $data['weather_behaviour']) : null,
            isset($data['initial_grip']) ? InitialGrip::from((string) $data['initial_grip']) : null,
            $sessions,
            isset($data['min_waiting_for_players']) ? (int) $data['min_waiting_for_players'] : null,
            isset($data['max_waiting_for_players']) ? (int) $data['max_waiting_for_players'] : null,
            isset($data['mandatory_pitstop']) ? MandatoryPitstop::fromArray((array) $data['mandatory_pitstop']) : null,
        );
    }
}
