<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Integration\RecordIntegrationSuccess;
use App\Actions\Weeks\ResolveWeek;
use App\Enums\ConfigApplicationStatus;
use App\Enums\IntegrationEventType;
use App\Enums\ReconcileResult;
use App\Enums\ServerStatus;
use App\Integration\Server\Data\ConfigPlan;
use App\Integration\Server\ServerDriverException;
use App\Integration\Server\ServerDriverManager;
use App\Models\ConfigApplication;
use App\Models\Server;
use App\Models\ServerOverride;
use App\Models\Week;
use App\Support\ScheduleWindow;
use App\Support\ServerSnapshot;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReconcileServer
{
    public const RETRY_OFFSETS_MINUTES = [1, 3, 5, 10];

    public const RETRY_INTERVAL_MINUTES = 10;

    public const START_GRACE_SECONDS = 120;

    private const LOCK_SECONDS = 300;

    private const UNEXPECTED_START_FAILURE = 'unexpected error while starting the server';

    public function __construct(
        private readonly WeekCalendar $calendar,
        private readonly ResolveWeek $resolveWeek,
        private readonly BuildConfigPlan $buildPlan,
        private readonly ApplyServerConfig $applyConfig,
        private readonly ServerDriverManager $drivers,
        private readonly ServerSnapshot $snapshots,
        private readonly RecordIntegrationFailure $recordFailure,
        private readonly RecordIntegrationSuccess $recordSuccess,
    ) {}

    public function handle(Server $server, ?CarbonImmutable $now = null, bool $force = false): ReconcileResult
    {
        $now ??= CarbonImmutable::now();

        if ($server->planning_paused && ! $force) {
            return ReconcileResult::Paused;
        }

        $lock = Cache::lock("server:{$server->id}:reconcile", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return ReconcileResult::Busy;
        }

        try {
            return $this->reconcile($server, $now, $force);
        } finally {
            $lock->release();
        }
    }

    private function reconcile(Server $server, CarbonImmutable $now, bool $force): ReconcileResult
    {
        $this->resolveWeek->due($now, $server->id);

        $override = $this->activeOverride($server, $now);

        if ($override !== null) {
            return $this->converge($server, $override->configPlan(), $override->week, $override->blockKey(), $now, $force);
        }

        $window = $this->calendar->scheduleWindowAt($now);
        $week = $this->weekFor($server, $window);
        $plan = $this->buildPlan->handle($server, $week, $window);

        if ($plan === null) {
            return ReconcileResult::NoConfig;
        }

        return $this->converge($server, $plan, $week, $window->key, $now, $force);
    }

    private function converge(Server $server, ConfigPlan $plan, ?Week $week, string $blockKey, CarbonImmutable $now, bool $force): ReconcileResult
    {
        if (! $force) {
            $desired = $this->normalize($this->applyConfig->snapshotFor($server, $plan));
            $lastOk = $this->lastOk($server);

            if ($lastOk !== null && $this->isLatest($server, $lastOk) && $this->hasOkFor($server, $blockKey) && $this->normalize($lastOk->snapshot) === $desired) {
                return $this->ensureRunning($server, $lastOk, $now);
            }

            if (! $this->isDue($server, $blockKey, $desired, $now)) {
                return ReconcileResult::Waiting;
            }
        }

        $application = $this->applyConfig->handle($server, $plan, $week, $blockKey, $plan->mode);

        return $application->status === ConfigApplicationStatus::Ok ? ReconcileResult::Applied : ReconcileResult::Failed;
    }

    private function activeOverride(Server $server, CarbonImmutable $now): ?ServerOverride
    {
        return ServerOverride::query()
            ->where('server_id', $server->id)
            ->active($now)
            ->orderByDesc('id')
            ->first();
    }

    private function weekFor(Server $server, ScheduleWindow $window): ?Week
    {
        return Week::query()
            ->where('server_id', $server->id)
            ->where('starts_at', $window->weekStartsAt)
            ->with(['track.pairedTrack', 'cars'])
            ->first();
    }

    private function lastOk(Server $server): ?ConfigApplication
    {
        return ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('status', ConfigApplicationStatus::Ok)
            ->orderByDesc('id')
            ->first();
    }

    private function isLatest(Server $server, ConfigApplication $lastOk): bool
    {
        return ! ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('id', '>', $lastOk->id)
            ->exists();
    }

    private function hasOkFor(Server $server, string $blockKey): bool
    {
        return ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('block_key', $blockKey)
            ->where('status', ConfigApplicationStatus::Ok)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $desired
     */
    private function isDue(Server $server, string $blockKey, array $desired, CarbonImmutable $now): bool
    {
        $failures = $this->failuresSinceLastOk($server, $blockKey);

        if ($failures->isEmpty()) {
            return true;
        }

        $last = $failures->last();

        if ($this->normalize($last->snapshot) !== $desired) {
            return true;
        }

        return $now->startOfMinute()->greaterThanOrEqualTo($this->nextRetryAt($failures));
    }

    /**
     * @return Collection<int, ConfigApplication>
     */
    private function failuresSinceLastOk(Server $server, string $blockKey): Collection
    {
        $attempts = ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('block_key', $blockKey)
            ->orderBy('attempt')
            ->orderBy('id')
            ->get();

        $lastOkIndex = $attempts
            ->filter(fn (ConfigApplication $attempt): bool => $attempt->status === ConfigApplicationStatus::Ok)
            ->keys()
            ->last();

        return $lastOkIndex === null ? $attempts : $attempts->slice($lastOkIndex + 1)->values();
    }

    /**
     * @param  Collection<int, ConfigApplication>  $failures
     */
    private function nextRetryAt(Collection $failures): CarbonImmutable
    {
        $count = $failures->count();

        if ($count <= count(self::RETRY_OFFSETS_MINUTES)) {
            return CarbonImmutable::instance($failures->first()->started_at)
                ->startOfMinute()
                ->addMinutes(self::RETRY_OFFSETS_MINUTES[$count - 1]);
        }

        return CarbonImmutable::instance($failures->last()->started_at)
            ->startOfMinute()
            ->addMinutes(self::RETRY_INTERVAL_MINUTES);
    }

    private function ensureRunning(Server $server, ConfigApplication $lastOk, CarbonImmutable $now): ReconcileResult
    {
        $snapshot = $this->snapshots->read($server);
        $status = $this->snapshots->statusAt($snapshot, $now);

        if ($status === ServerStatus::Online) {
            if (Cache::has(IntegrationEventType::ServerDown->failuresKey($server))) {
                $this->recordSuccess->handle($server, IntegrationEventType::ServerDown);
            }

            return ReconcileResult::UpToDate;
        }

        if ($status !== ServerStatus::Offline || $snapshot === null || $this->withinStartGrace($snapshot['fetched_at'], $lastOk)) {
            return ReconcileResult::UpToDate;
        }

        $this->recordFailure->handle($server, IntegrationEventType::ServerDown, $this->start($server));

        return ReconcileResult::Started;
    }

    private function withinStartGrace(string $fetchedAt, ConfigApplication $lastOk): bool
    {
        $appliedAt = $lastOk->finished_at ?? $lastOk->started_at;

        return CarbonImmutable::parse($fetchedAt)
            ->lessThanOrEqualTo(CarbonImmutable::instance($appliedAt)->addSeconds(self::START_GRACE_SECONDS));
    }

    private function start(Server $server): string
    {
        try {
            $result = $this->drivers->for($server)->start();

            return $result->ok ? 'server was stopped, start requested' : 'server was stopped and the start failed: '.$result->message;
        } catch (ServerDriverException $exception) {
            return 'server was stopped and the start failed: '.$exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return self::UNEXPECTED_START_FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function normalize(array $snapshot): array
    {
        $carRefs = array_values(array_unique(array_map('strval', (array) ($snapshot['car_refs'] ?? []))));
        sort($carRefs);
        $snapshot['car_refs'] = $carRefs;
        $snapshot += ['race_duration_type' => 'time', 'race_laps' => null, 'start_hour' => 14, 'weather' => 'clear'];

        return $this->sortKeys($snapshot);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sortKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortKeys($value);
            }
        }

        ksort($data);

        return $data;
    }
}
