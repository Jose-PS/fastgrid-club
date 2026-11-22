<?php

declare(strict_types=1);

namespace App\Integration\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class ServerCircuitBreaker
{
    public const THRESHOLD = 5;

    private const TTL_SECONDS = 86400;

    public function recordFailure(Server $server): void
    {
        $failuresKey = $this->failuresKey($server);

        Cache::add($failuresKey, 0, self::TTL_SECONDS);

        if ((int) Cache::increment($failuresKey) >= self::THRESHOLD) {
            Cache::put($this->unreachableKey($server), true, self::TTL_SECONDS);
        }
    }

    public function recordSuccess(Server $server): void
    {
        Cache::forget($this->failuresKey($server));
        Cache::forget($this->unreachableKey($server));
    }

    public function isUnreachable(Server $server): bool
    {
        return Cache::has($this->unreachableKey($server));
    }

    public function consecutiveFailures(Server $server): int
    {
        return (int) Cache::get($this->failuresKey($server), 0);
    }

    private function failuresKey(Server $server): string
    {
        return "server:{$server->id}:failures";
    }

    private function unreachableKey(Server $server): string
    {
        return "server:{$server->id}:unreachable";
    }
}
