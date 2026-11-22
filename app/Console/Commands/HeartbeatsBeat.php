<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HeartbeatSource;
use App\Support\Heartbeats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('heartbeats:beat')]
#[Description('Record the scheduler heartbeat')]
class HeartbeatsBeat extends Command
{
    public function handle(Heartbeats $heartbeats): int
    {
        $heartbeats->beat(HeartbeatSource::Scheduler);

        return self::SUCCESS;
    }
}
