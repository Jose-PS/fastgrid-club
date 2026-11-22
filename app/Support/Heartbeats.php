<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\HeartbeatSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class Heartbeats
{
    public const BACKUP_OFFSITE_KEY = 'heartbeat:backup:offsite';

    public function beat(HeartbeatSource $source, ?CarbonImmutable $at = null): void
    {
        Cache::forever($source->cacheKey(), ($at ?? CarbonImmutable::now())->getTimestamp());
    }

    public function lastBeat(HeartbeatSource $source): ?CarbonImmutable
    {
        $value = Cache::get($source->cacheKey());

        if (! is_numeric($value)) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) $value, (string) config('app.timezone'));
    }

    public function ageInSeconds(HeartbeatSource $source, ?CarbonImmutable $now = null): ?int
    {
        $last = $this->lastBeat($source);

        if ($last === null) {
            return null;
        }

        return max(0, (int) $last->diffInSeconds($now ?? CarbonImmutable::now(), false));
    }

    public function backupWentOffsite(): ?bool
    {
        $value = Cache::get(self::BACKUP_OFFSITE_KEY);

        return is_numeric($value) ? (int) $value === 1 : null;
    }

    /**
     * @return list<array{source: string, last_beat_at: string|null, age_seconds: int|null, healthy: bool, offsite: bool|null}>
     */
    public function overview(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return array_map(function (HeartbeatSource $source) use ($now): array {
            $age = $this->ageInSeconds($source, $now);

            return [
                'source' => $source->value,
                'last_beat_at' => $this->lastBeat($source)?->toIso8601String(),
                'age_seconds' => $age,
                'healthy' => $age !== null && $age <= $source->staleAfterSeconds(),
                'offsite' => $source === HeartbeatSource::Backup ? $this->backupWentOffsite() : null,
            ];
        }, HeartbeatSource::cases());
    }
}
