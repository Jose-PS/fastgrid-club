<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Donations\CurrentDonationPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('donations:ensure-period')]
#[Description('Create the donation period of the current month copying the previous goal and covered amounts')]
class DonationsEnsurePeriod extends Command
{
    public function handle(CurrentDonationPeriod $currentPeriod): int
    {
        $period = $currentPeriod->handle();

        $this->info("Donation period: {$period->month->format('Y-m')}.");

        return self::SUCCESS;
    }
}
