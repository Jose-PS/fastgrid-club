<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Heartbeats\CheckHeartbeats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('heartbeats:check')]
#[Description('Open or close the alerts for missing scheduler, queue and backup heartbeats')]
class HeartbeatsCheck extends Command
{
    public function handle(CheckHeartbeats $check): int
    {
        foreach ($check->handle() as $source => $stale) {
            $this->line("{$source}: ".($stale ? 'stale' : 'fresh'));
        }

        return self::SUCCESS;
    }
}
