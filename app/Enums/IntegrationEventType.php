<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Server;

enum IntegrationEventType: string
{
    case PollFailed = 'poll_failed';
    case IngestFailed = 'ingest_failed';
    case SyncFailed = 'sync_failed';
    case ApplyFailed = 'apply_failed';
    case LogGap = 'log_gap';
    case ServerDown = 'server_down';

    public function failuresKey(Server $server): string
    {
        return "server:{$server->id}:integration:{$this->value}:failures";
    }

    public function mailLockKey(Server $server): string
    {
        return "server:{$server->id}:integration:{$this->value}:mail";
    }
}
