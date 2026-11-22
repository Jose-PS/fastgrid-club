<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Servers\IngestServerLogs;
use App\Actions\Servers\PollServer;
use App\Enums\ConfigApplicationStatus;
use App\Enums\IntegrationEventType;
use App\Enums\ServerStatus;
use App\Enums\TrackMode;
use App\Integration\Server\ServerCircuitBreaker;
use App\Models\CatalogSync;
use App\Models\ConfigApplication;
use App\Models\IntegrationEvent;
use App\Models\LogCursor;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class AdminServerStatus
{
    private const HISTORY_DAYS = 30;

    public function __construct(
        private readonly ServerSnapshot $snapshots,
        private readonly WeekCalendar $calendar,
        private readonly ServerCircuitBreaker $breaker,
    ) {}

    /**
     * @return array{status: string, players: int|null, fetched_at: string|null}
     */
    public function live(Server $server, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $snapshot = $this->snapshots->read($server);
        $status = $this->snapshots->statusAt($snapshot, $now);

        return [
            'status' => $status->value,
            'players' => $status === ServerStatus::Online && $snapshot !== null ? $snapshot['players'] : null,
            'fetched_at' => $snapshot['fetched_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Server $server, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $window = $this->calendar->scheduleWindowAt($now);

        return [
            'live' => $this->live($server, $now),
            'block' => ($window->isRaceWeekend() ? TrackMode::RaceWeekend : TrackMode::Practice)->value,
            'block_key' => $window->key,
            'planning_paused' => $server->planning_paused,
            'pending_config' => $this->pendingConfig($server, $window->key),
            'last_apply' => $this->lastApply($server),
            'last_catalog_sync' => $this->lastCatalogSync($server),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Server $server, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cursor = LogCursor::query()->where('server_id', $server->id)->first();
        $meta = is_array($server->meta) ? $server->meta : [];
        $metrics = Cache::get(IngestServerLogs::metricsKey($server));
        $latency = Cache::get(PollServer::latencyKey($server));
        $lastPollOk = Cache::get(PollServer::lastSuccessKey($server));

        return [
            ...$this->summary($server, $now),
            'id' => $server->id,
            'name' => $server->name,
            'latency_ms' => is_numeric($latency) ? (int) $latency : null,
            'last_poll_ok_at' => is_string($lastPollOk) ? $lastPollOk : null,
            'circuit' => [
                'open' => $this->breaker->isUnreachable($server),
                'failures' => $this->breaker->consecutiveFailures($server),
            ],
            'cursor' => [
                'last_read_at' => $cursor?->last_read_at->toIso8601String(),
                'last_gap_at' => $cursor?->last_gap_at?->toIso8601String(),
            ],
            'ingest' => is_array($metrics) ? [
                'at' => isset($metrics['at']) ? (string) $metrics['at'] : null,
                'lines' => (int) ($metrics['lines'] ?? 0),
                'laps' => (int) ($metrics['laps'] ?? 0),
                'laps_discarded' => (int) ($metrics['laps_discarded'] ?? 0),
                'laps_rejected' => (int) ($metrics['laps_rejected'] ?? 0),
            ] : null,
            'gaps_30d' => IntegrationEvent::query()
                ->where('server_id', $server->id)
                ->where('type', IntegrationEventType::LogGap)
                ->where('created_at', '>=', $now->subDays(self::HISTORY_DAYS))
                ->count(),
            'versions' => [
                'panel' => isset($meta['panel_version']) ? (string) $meta['panel_version'] : null,
                'image' => isset($meta['image_version']) ? (string) $meta['image_version'] : null,
            ],
        ];
    }

    /**
     * @return array{status: string, block_key: string|null, attempt: int, started_at: string, finished_at: string|null}|null
     */
    public function lastApply(Server $server): ?array
    {
        $application = ConfigApplication::query()
            ->where('server_id', $server->id)
            ->orderByDesc('id')
            ->first();

        if ($application === null) {
            return null;
        }

        return [
            'status' => $application->status->value,
            'block_key' => $application->block_key,
            'attempt' => $application->attempt,
            'started_at' => $application->started_at->toIso8601String(),
            'finished_at' => $application->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{finished_at: string, cars: array{created: int, updated: int, deactivated: int}, tracks: array{created: int, updated: int, deactivated: int}, unpaired: int}|null
     */
    public function lastCatalogSync(Server $server): ?array
    {
        $sync = CatalogSync::query()->where('server_id', $server->id)->orderByDesc('finished_at')->orderByDesc('id')->first();

        if ($sync === null) {
            return null;
        }

        return [
            'finished_at' => $sync->finished_at->toIso8601String(),
            'cars' => $sync->summary['cars'],
            'tracks' => $sync->summary['tracks'],
            'unpaired' => count($sync->summary['unpaired_race_tracks']),
        ];
    }

    public function pendingConfig(Server $server, string $blockKey): bool
    {
        $last = ConfigApplication::query()
            ->where('server_id', $server->id)
            ->where('block_key', $blockKey)
            ->orderByDesc('attempt')
            ->orderByDesc('id')
            ->first();

        return $last !== null && $last->status === ConfigApplicationStatus::Failed;
    }
}
