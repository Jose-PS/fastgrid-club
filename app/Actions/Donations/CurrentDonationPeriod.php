<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Models\DonationPeriod;
use Carbon\CarbonImmutable;

class CurrentDonationPeriod
{
    public function find(?CarbonImmutable $now = null): ?DonationPeriod
    {
        return DonationPeriod::query()->whereDate('month', $this->monthOf($now))->first();
    }

    public function handle(?CarbonImmutable $now = null): DonationPeriod
    {
        $month = $this->monthOf($now);

        $existing = DonationPeriod::query()->whereDate('month', $month)->first();

        if ($existing !== null) {
            return $existing;
        }

        $previous = DonationPeriod::query()->whereDate('month', '<', $month)->orderByDesc('month')->first();

        return DonationPeriod::query()->createOrFirst(
            ['month' => $month],
            [
                'goal_cents' => $previous->goal_cents ?? 0,
                'covered_cents' => $previous->covered_cents ?? 0,
            ],
        );
    }

    private function monthOf(?CarbonImmutable $now): string
    {
        return ($now ?? CarbonImmutable::now())
            ->setTimezone(config()->string('mvp.timezone'))
            ->startOfMonth()
            ->toDateString();
    }
}
