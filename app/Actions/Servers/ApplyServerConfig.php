<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Integration\RecordIntegrationSuccess;
use App\Enums\ConfigApplicationStatus;
use App\Enums\IntegrationEventType;
use App\Enums\RaceDurationType;
use App\Enums\TrackMode;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\Data\ConfigSnapshot;
use App\Integration\Server\Data\ServerSecrets;
use App\Integration\Server\ServerDriver;
use App\Integration\Server\ServerDriverException;
use App\Integration\Server\ServerDriverManager;
use App\Models\Car;
use App\Models\ConfigApplication;
use App\Models\Server;
use App\Models\Track;
use App\Models\Week;
use Throwable;

class ApplyServerConfig
{
    private const ERROR_LIMIT = 500;

    private const UNEXPECTED_FAILURE = 'unexpected error while applying the config';

    public function __construct(
        private readonly ServerDriverManager $drivers,
        private readonly RecordIntegrationFailure $recordFailure,
        private readonly RecordIntegrationSuccess $recordSuccess,
    ) {}

    public function handle(Server $server, ConfigPlan $plan, ?Week $week = null, ?string $blockKey = null, ?TrackMode $block = null, ?ServerSecrets $secrets = null, bool $enforceNamePrefix = true): ConfigApplication
    {
        $track = $this->trackFor($plan);
        $effective = $this->clamp($server, $plan, $track);

        $application = ConfigApplication::query()->create([
            'server_id' => $server->id,
            'week_id' => $week?->id,
            'block_key' => $blockKey,
            'block' => $block?->value,
            'attempt' => $this->nextAttempt($server, $blockKey),
            'status' => ConfigApplicationStatus::Pending,
            'snapshot' => $this->snapshotOf($effective),
            'started_at' => now(),
        ]);

        $error = $this->validate($server, $plan, $track, $enforceNamePrefix);

        if ($error === null) {
            $error = $this->execute($server, $effective, $secrets);
        }

        return $this->finish($server, $application, $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(Server $server, ConfigPlan $plan): array
    {
        return $this->snapshotOf($this->clamp($server, $plan, $this->trackFor($plan)));
    }

    private function trackFor(ConfigPlan $plan): ?Track
    {
        return Track::query()->where('token', $plan->trackRef)->where('mode', $plan->mode)->first();
    }

    private function clamp(Server $server, ConfigPlan $plan, ?Track $track): ConfigPlan
    {
        $limits = [$plan->maxPlayers, $server->max_players];

        if ($track !== null) {
            $limits[] = $track->max_pit_slot;
        }

        return $plan->withMaxPlayers(min($limits));
    }

    private function nextAttempt(Server $server, ?string $blockKey): int
    {
        if ($blockKey === null) {
            return 1;
        }

        $last = ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('block_key', $blockKey)
            ->max('attempt');

        return ((int) $last) + 1;
    }

    private function validate(Server $server, ConfigPlan $plan, ?Track $track, bool $enforceNamePrefix): ?string
    {
        if ($track === null || ! $track->active) {
            return 'invalid plan: the track does not exist or is inactive';
        }

        $carRefs = array_values(array_unique($plan->carRefs));

        if (count($carRefs) < 1) {
            return 'invalid plan: the plan needs at least 1 car';
        }

        $activeCars = Car::query()->whereIn('internal_name', $carRefs)->where('active', true)->count();

        if ($activeCars !== count($carRefs)) {
            return 'invalid plan: some cars do not exist or are inactive';
        }

        $prefix = (string) $server->ingame_prefix;

        if ($enforceNamePrefix && $prefix !== '' && mb_stripos($plan->serverName, $prefix) !== 0) {
            return 'invalid plan: the server name does not start with the in-game prefix';
        }

        return null;
    }

    private function execute(Server $server, ConfigPlan $plan, ?ServerSecrets $secrets): ?string
    {
        try {
            $driver = $this->drivers->for($server);

            $applied = $driver->apply($plan, $secrets);

            if (! $applied->ok) {
                return $applied->message;
            }

            $differences = $this->differences($plan, $driver->currentConfig());

            if ($differences !== []) {
                return 'verification failed, fields differ: '.implode(', ', $differences);
            }

            return $this->ensureRunning($driver);
        } catch (ServerDriverException $exception) {
            return $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return self::UNEXPECTED_FAILURE;
        }
    }

    private function ensureRunning(ServerDriver $driver): ?string
    {
        if ($driver->status()->running) {
            return null;
        }

        $started = $driver->start();

        return $started->ok ? null : $started->message;
    }

    /**
     * @return list<string>
     */
    private function differences(ConfigPlan $plan, ConfigSnapshot $current): array
    {
        $carsMatch = $this->sorted($plan->carRefs) === $this->sorted($current->carRefs);
        $checksDurations = $plan->mode !== TrackMode::Practice;

        $checks = [
            'serverName' => $current->serverName === $plan->serverName,
            'maxPlayers' => $current->maxPlayers === $plan->maxPlayers,
            'trackRef' => $current->trackRef === $plan->trackRef,
            'mode' => $current->mode === $plan->mode,
            'carRefs' => $carsMatch,
            'qualifyingSeconds' => ! $checksDurations || $current->qualifyingSeconds === $plan->qualifyingSeconds,
            'raceSeconds' => ! $checksDurations || $plan->raceSeconds === null || $current->raceSeconds === $plan->raceSeconds,
            'raceDurationType' => ! $checksDurations || $current->raceDurationType === $plan->raceDurationType,
            'raceLaps' => ! $checksDurations || $plan->raceDurationType !== RaceDurationType::Laps || $current->raceLaps === $plan->raceLaps,
            'practiceSeconds' => $plan->practiceSeconds === null || ($current->practiceSeconds ?? 0) === $plan->practiceSeconds,
            'cycleEnabled' => $current->cycleEnabled === $plan->cycleEnabled,
        ];

        return array_keys(array_filter($checks, fn (bool $matches): bool => ! $matches));
    }

    /**
     * @param  list<string>  $refs
     * @return list<string>
     */
    private function sorted(array $refs): array
    {
        $refs = array_values(array_unique($refs));
        sort($refs);

        return $refs;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(ConfigPlan $plan): array
    {
        return $plan->toArray();
    }

    private function finish(Server $server, ConfigApplication $application, ?string $error): ConfigApplication
    {
        if ($error === null) {
            $application->forceFill(['status' => ConfigApplicationStatus::Ok, 'finished_at' => now()])->save();
            $this->recordSuccess->handle($server, IntegrationEventType::ApplyFailed);

            return $application;
        }

        $error = mb_substr(trim($error), 0, self::ERROR_LIMIT);

        $application->forceFill([
            'status' => ConfigApplicationStatus::Failed,
            'error' => $error,
            'finished_at' => now(),
        ])->save();

        $this->recordFailure->handle($server, IntegrationEventType::ApplyFailed, $error);

        return $application;
    }
}
