<?php

declare(strict_types=1);

namespace App\Integration\Server;

use App\Enums\InitialGrip;
use App\Enums\RaceDurationType;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;
use App\Integration\Server\Data\Catalog;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ConfigSnapshot;
use App\Integration\Server\Data\EditableConfig;
use App\Integration\Server\Data\LiveDriver;
use App\Integration\Server\Data\LiveSnapshot;
use App\Integration\Server\Data\LogEvent;
use App\Integration\Server\Data\LogEvents;
use App\Integration\Server\Data\LogTail;
use App\Integration\Server\Data\OperationResult;
use App\Integration\Server\Data\ServerSecrets;
use App\Integration\Server\Data\ServerStatusReport;

class FakeDriver implements ServerDriver
{
    private ?ConfigPlan $plan = null;

    private ?ServerSecrets $secrets = null;

    public function status(): ServerStatusReport
    {
        return new ServerStatusReport(true, 'running', null);
    }

    public function live(): LiveSnapshot
    {
        return new LiveSnapshot(true, 2, [
            new LiveDriver('1', 'Piloto Uno', 11, 5, 92500, 91800),
            new LiveDriver('2', 'Piloto Dos', 22, 4, 93100, 92400),
        ]);
    }

    public function logTail(int $lines): LogTail
    {
        $all = ['Server updated: 2 players', 'Server updated: 2 players'];

        return new LogTail(array_slice($all, -max(0, $lines)));
    }

    public function logEvents(int $afterId, int $limit): LogEvents
    {
        $all = ['Server updated: 2 players', 'Server updated: 2 players'];

        $events = [];

        foreach ($all as $index => $line) {
            if ($index + 1 > $afterId) {
                $events[] = new LogEvent($index + 1, $line);
            }
        }

        $events = array_slice($events, 0, max(0, $limit));

        return new LogEvents($events, $events === [] ? null : $events[0]->id, $events === [] ? null : $events[array_key_last($events)]->id);
    }

    public function catalog(): Catalog
    {
        return ZinoMetadata::fromFile(database_path('data/zino/metadata.json'));
    }

    public function currentConfig(): ConfigSnapshot
    {
        $plan = $this->plan;

        if ($plan === null) {
            return new ConfigSnapshot(null, null, [], null, null, null, null, null, null);
        }

        return new ConfigSnapshot(
            $plan->mode,
            $plan->trackRef,
            $plan->carRefs,
            $plan->serverName,
            $plan->maxPlayers,
            $plan->qualifyingSeconds,
            $plan->raceSeconds,
            $plan->practiceSeconds,
            $plan->cycleEnabled,
            $plan->raceDurationType,
            $plan->raceLaps,
        );
    }

    public function editableConfig(): EditableConfig
    {
        $plan = $this->plan;
        $secrets = $this->secrets;

        if ($plan === null || $secrets === null) {
            return new EditableConfig(
                'Fake server',
                20,
                true,
                ServerType::Unranked,
                TuningType::Allowed,
                '',
                '',
                '',
                TrackMode::Practice,
                null,
                WeatherType::Clear,
                WeatherBehaviour::Static,
                InitialGrip::Optimum,
                [],
                [
                    'practice' => ['length_sec' => 300, 'hour' => 14, 'minute' => 0, 'time_multiplier' => 1, 'max_wait_to_box' => 10, 'overtime_waiting_next_session' => 10],
                    'qualify' => ['length_sec' => 600, 'hour' => 14, 'minute' => 0, 'time_multiplier' => 1, 'max_wait_to_box' => 10, 'overtime_waiting_next_session' => 10],
                    'warmup' => ['length_sec' => 0, 'hour' => 14, 'minute' => 0, 'time_multiplier' => 1, 'max_wait_to_box' => 10, 'overtime_waiting_next_session' => 10],
                    'race' => [
                        'length_sec' => 1500,
                        'hour' => 14,
                        'minute' => 0,
                        'time_multiplier' => 1,
                        'max_wait_to_box' => 10,
                        'overtime_waiting_next_session' => 10,
                        'duration_type' => RaceDurationType::Time->value,
                        'laps' => 10,
                        'min_waiting_for_players' => 10,
                        'max_waiting_for_players' => 30,
                        'mandatory_pitstop_enabled' => false,
                        'mandatory_pitstop_window_seconds' => 600,
                        'mandatory_pitstop_refuel' => true,
                        'mandatory_pitstop_tyre_change' => true,
                    ],
                ],
            );
        }

        $sessions = [];

        foreach (ConfigPlan::SESSIONS as $name) {
            $settings = array_key_exists($name, $plan->sessionSettings) ? $plan->sessionSettings[$name] : null;

            $sessions[$name] = [
                'length_sec' => match ($name) {
                    'practice' => $plan->practiceSeconds ?? 300,
                    'qualify' => $plan->qualifyingSeconds,
                    'warmup' => $plan->warmupSeconds ?? 0,
                    default => $plan->raceSeconds ?? 1500,
                },
                'hour' => $settings->hour ?? $plan->startHour,
                'minute' => $settings->minute ?? 0,
                'time_multiplier' => $settings->timeMultiplier ?? 1,
                'max_wait_to_box' => $settings->maxWaitToBox ?? 10,
                'overtime_waiting_next_session' => $settings->overtimeWaitingNextSession ?? 10,
            ];
        }

        $sessions['race'] += [
            'duration_type' => $plan->raceDurationType->value,
            'laps' => $plan->raceLaps ?? 10,
            'min_waiting_for_players' => $plan->minWaitingForPlayers ?? 10,
            'max_waiting_for_players' => $plan->maxWaitingForPlayers ?? 30,
            'mandatory_pitstop_enabled' => $plan->mandatoryPitstop->enabled ?? false,
            'mandatory_pitstop_window_seconds' => $plan->mandatoryPitstop->windowSeconds ?? 600,
            'mandatory_pitstop_refuel' => $plan->mandatoryPitstop->refuel ?? true,
            'mandatory_pitstop_tyre_change' => $plan->mandatoryPitstop->tyreChange ?? true,
        ];

        return new EditableConfig(
            $plan->serverName,
            $plan->maxPlayers,
            $plan->cycleEnabled,
            $plan->serverType ?? ServerType::Unranked,
            $plan->tuningType ?? TuningType::Allowed,
            $secrets->driverPassword ?? '',
            $secrets->adminPassword ?? '',
            $secrets->spectatorPassword ?? '',
            $plan->mode,
            $plan->trackRef,
            $plan->weather,
            $plan->weatherBehaviour ?? WeatherBehaviour::Static,
            $plan->initialGrip ?? InitialGrip::Optimum,
            $plan->carRefs,
            $sessions,
        );
    }

    public function apply(ConfigPlan $plan, ?ServerSecrets $secrets = null): OperationResult
    {
        $this->plan = $plan;
        $this->secrets = new ServerSecrets(
            $secrets->driverPassword ?? $this->secrets?->driverPassword,
            $secrets->adminPassword ?? $this->secrets?->adminPassword,
            $secrets->spectatorPassword ?? $this->secrets?->spectatorPassword,
        );

        return new OperationResult(true, 'applied');
    }

    public function start(): OperationResult
    {
        return new OperationResult(true, 'started');
    }
}
