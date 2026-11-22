<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\HeartbeatSource;
use App\Support\Heartbeats;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function handle(Heartbeats $heartbeats): void
    {
        $heartbeats->beat(HeartbeatSource::Queue);
    }
}
