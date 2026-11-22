<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Weeks\EnsureWeeks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('weeks:ensure')]
#[Description('Create the current and next week of every active server and advance week statuses')]
class WeeksEnsure extends Command
{
    public function handle(EnsureWeeks $ensureWeeks): int
    {
        $created = $ensureWeeks->handle();

        $this->info("Weeks created: {$created}.");

        return self::SUCCESS;
    }
}
