<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Integration\RecordIntegrationSuccess;
use App\Enums\IntegrationEventType;
use App\Integration\Server\Data\LiveDriver;
use App\Integration\Server\ServerCircuitBreaker;
use App\Integration\Server\ServerDriverException;
use App\Integration\Server\ServerDriverManager;
use App\Models\Server;
use App\Support\ServerSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PollServer
{
    public const SLOW_INTERVAL_SECONDS = 60;

    private const UNEXPECTED_FAILURE = 'unexpected error while polling the server';

    public function __construct(
        private readonly ServerDriverManager $drivers,
        private readonly ServerCircuitBreaker $breaker,
        private readonly ServerSnapshot $snapshots,
        private readonly RecordIntegrationFailure $recordFailure,
        private readonly RecordIntegrationSuccess $recordSuccess,
    ) {}

    public static function lastAttemptKey(Server $server): string
    {
        return "server:{$server->id}:poll:last_attempt_at";
    }

    public static function lastSuccessKey(Server $server): string
    {
        return "server:{$server->id}:poll:last_success_at";
    }

    public static function latencyKey(Server $server): string
    {
        return "server:{$server->id}:poll:latency_ms";
    }

    public function handle(Server $server, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->shouldSkip($server, $now)) {
            return false;
        }

        Cache::forever(self::lastAttemptKey($server), $now->getTimestamp());

        $startedAt = hrtime(true);

        try {
            $driver = $this->drivers->for($server);
            $status = $driver->status();
            $live = $driver->live();
        } catch (ServerDriverException $exception) {
            $this->fail($server, $now, $exception->getMessage());

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($server, $now, self::UNEXPECTED_FAILURE);

            return true;
        }

        $this->snapshots->store(
            $server,
            $live->running,
            $live->players,
            true,
            $now,
            $status->state,
            array_map(fn (LiveDriver $driver): array => [
                'name' => $driver->name,
                'car' => $driver->carRef,
                'number' => $driver->number,
                'laps' => $driver->laps,
                'last_lap_ms' => $driver->lastLapMs,
                'best_lap_ms' => $driver->bestLapMs,
            ], $live->drivers),
        );

        $server->forceFill(['last_seen_at' => $now])->save();

        Cache::forever(self::lastSuccessKey($server), $now->toIso8601String());
        Cache::forever(self::latencyKey($server), (int) round((hrtime(true) - $startedAt) / 1_000_000));

        $this->recordSuccess->handle($server, IntegrationEventType::PollFailed);

        return true;
    }

    private function shouldSkip(Server $server, CarbonImmutable $now): bool
    {
        if (! $this->breaker->isUnreachable($server)) {
            return false;
        }

        $lastAttempt = Cache::get(self::lastAttemptKey($server));

        return is_numeric($lastAttempt) && $now->getTimestamp() - (int) $lastAttempt < self::SLOW_INTERVAL_SECONDS;
    }

    private function fail(Server $server, CarbonImmutable $now, string $summary): void
    {
        $this->snapshots->store($server, false, 0, false, $now);

        $this->recordFailure->handle($server, IntegrationEventType::PollFailed, $summary);
    }
}
