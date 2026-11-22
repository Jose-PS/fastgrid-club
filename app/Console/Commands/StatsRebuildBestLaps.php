<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stats\RebuildBestLaps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stats:rebuild-best-laps')]
#[Description('Recompute the best laps from the stored laps, only ever improving existing records')]
class StatsRebuildBestLaps extends Command
{
    public function handle(RebuildBestLaps $rebuild): int
    {
        $this->info('Best laps written: '.$rebuild->handle().'.');

        return self::SUCCESS;
    }
}
