<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HeartbeatSource;
use App\Support\Heartbeats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('heartbeats:alive {source : scheduler, queue or backup} {--max-age=180 : Maximum heartbeat age in seconds}')]
#[Description('Exit with an error when the heartbeat of a process is missing or too old')]
class HeartbeatsAlive extends Command
{
    public function handle(Heartbeats $heartbeats): int
    {
        $source = HeartbeatSource::tryFrom((string) $this->argument('source'));

        if ($source === null) {
            $this->error('Unknown heartbeat source.');

            return self::INVALID;
        }

        $age = $heartbeats->ageInSeconds($source);

        if ($age === null || $age > (int) $this->option('max-age')) {
            $this->error("{$source->value}: ".($age === null ? 'no heartbeat' : "last heartbeat {$age}s ago"));

            return self::FAILURE;
        }

        $this->line("{$source->value}: last heartbeat {$age}s ago");

        return self::SUCCESS;
    }
}
