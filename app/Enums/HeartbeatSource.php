<?php

declare(strict_types=1);

namespace App\Enums;

enum HeartbeatSource: string
{
    case Scheduler = 'scheduler';
    case Queue = 'queue';
    case Backup = 'backup';

    public function cacheKey(): string
    {
        return "heartbeat:{$this->value}";
    }

    public function watchingSinceKey(): string
    {
        return "heartbeat:{$this->value}:watching_since";
    }

    public function staleAfterSeconds(): int
    {
        return match ($this) {
            self::Scheduler, self::Queue => 5 * 60,
            self::Backup => 36 * 3600,
        };
    }

    public function alertType(): AlertType
    {
        return match ($this) {
            self::Scheduler => AlertType::SchedulerHeartbeatMissing,
            self::Queue => AlertType::QueueHeartbeatMissing,
            self::Backup => AlertType::BackupStale,
        };
    }

    public function alertSummaryKey(): string
    {
        return match ($this) {
            self::Scheduler => 'admin.alerts.schedulerHeartbeatMissing',
            self::Queue => 'admin.alerts.queueHeartbeatMissing',
            self::Backup => 'admin.alerts.backupStale',
        };
    }

    /**
     * @return array<string, int>
     */
    public function alertSummaryParams(): array
    {
        return match ($this) {
            self::Scheduler, self::Queue => ['minutes' => intdiv($this->staleAfterSeconds(), 60)],
            self::Backup => ['hours' => intdiv($this->staleAfterSeconds(), 3600)],
        };
    }
}
