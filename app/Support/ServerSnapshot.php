<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServerStatus;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class ServerSnapshot
{
    public const TTL_SECONDS = 30;

    public function key(Server $server): string
    {
        return "server:{$server->id}:snapshot";
    }

    /**
     * @param  list<array{name: string, car: string, number: int|null, laps: int, last_lap_ms: int|null, best_lap_ms: int|null}>  $drivers
     */
    public function store(Server $server, bool $running, int $players, bool $reachable, CarbonImmutable $fetchedAt, ?string $state = null, array $drivers = []): void
    {
        Cache::put($this->key($server), [
            'running' => $running,
            'players' => $players,
            'fetched_at' => $fetchedAt->setTimezone('UTC')->format('c'),
            'reachable' => $reachable,
            'state' => $state,
            'drivers' => $drivers,
        ], self::TTL_SECONDS);
    }

    /**
     * @return array{running: bool, players: int, fetched_at: string, reachable: bool, state: string|null, drivers: list<array{name: string, car: string, number: int|null, laps: int, last_lap_ms: int|null, best_lap_ms: int|null}>}|null
     */
    public function read(Server $server): ?array
    {
        $snapshot = Cache::get($this->key($server));

        if (! is_array($snapshot) || ! isset($snapshot['running'], $snapshot['players'], $snapshot['fetched_at'], $snapshot['reachable'])) {
            return null;
        }

        return [
            'running' => (bool) $snapshot['running'],
            'players' => (int) $snapshot['players'],
            'fetched_at' => (string) $snapshot['fetched_at'],
            'reachable' => (bool) $snapshot['reachable'],
            'state' => isset($snapshot['state']) ? (string) $snapshot['state'] : null,
            'drivers' => is_array($snapshot['drivers'] ?? null) ? array_values($snapshot['drivers']) : [],
        ];
    }

    public function forget(Server $server): void
    {
        Cache::forget($this->key($server));
    }

    /**
     * @param  array{running: bool, players: int, fetched_at: string, reachable: bool, state: string|null, drivers: list<array{name: string, car: string, number: int|null, laps: int, last_lap_ms: int|null, best_lap_ms: int|null}>}|null  $snapshot
     */
    public function statusAt(?array $snapshot, CarbonImmutable $now): ServerStatus
    {
        if ($snapshot === null || ! $snapshot['reachable'] || $this->isStale($snapshot, $now)) {
            return ServerStatus::Unavailable;
        }

        return $snapshot['running'] ? ServerStatus::Online : ServerStatus::Offline;
    }

    /**
     * @param  array{running: bool, players: int, fetched_at: string, reachable: bool, state: string|null, drivers: list<array{name: string, car: string, number: int|null, laps: int, last_lap_ms: int|null, best_lap_ms: int|null}>}  $snapshot
     */
    private function isStale(array $snapshot, CarbonImmutable $now): bool
    {
        return CarbonImmutable::parse($snapshot['fetched_at'])
            ->addSeconds((int) config('mvp.server_snapshot.stale_after_seconds'))
            ->lessThan($now);
    }
}
