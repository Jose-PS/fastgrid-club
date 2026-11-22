<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Weeks\ResolveWeek;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('weeks:resolve {week? : Week id; without it every closed voting week is resolved}')]
#[Description('Resolve the voting of closed weeks and schedule their configuration')]
class WeeksResolve extends Command
{
    public function handle(ResolveWeek $resolveWeek): int
    {
        $week = $this->argument('week');

        if ($week === null) {
            $resolved = $resolveWeek->due();

            $this->info("Weeks resolved: {$resolved}.");

            return self::SUCCESS;
        }

        if (! $resolveWeek->handle((int) $week)) {
            $this->warn("Week #{$week} was not resolved: it is missing, not in voting or its voting is still open.");

            return self::FAILURE;
        }

        $this->info("Week #{$week} resolved.");

        return self::SUCCESS;
    }
}
