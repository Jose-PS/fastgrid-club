<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertType: string
{
    case WeekFallbackPendingReview = 'week_fallback_pending_review';
    case IntegrationFailing = 'integration_failing';
    case LogGap = 'log_gap';
    case CatalogStale = 'catalog_stale';
    case SchedulerHeartbeatMissing = 'scheduler_heartbeat_missing';
    case QueueHeartbeatMissing = 'queue_heartbeat_missing';
    case BackupStale = 'backup_stale';
}
