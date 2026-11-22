<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WeekStatus;
use App\Models\Server;
use App\Models\Week;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Week>
 */
class WeekFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_merge(['server_id' => Server::factory(), 'status' => WeekStatus::Voting], $this->windowFor(CarbonImmutable::now()));
    }

    public function startingAt(CarbonImmutable $instant): static
    {
        return $this->state(fn (): array => $this->windowFor($instant));
    }

    public function status(WeekStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * @return array<string, CarbonImmutable>
     */
    private function windowFor(CarbonImmutable $instant): array
    {
        $calendar = new WeekCalendar;
        $start = $calendar->weekStartFor($instant);

        return [
            'starts_at' => $start,
            'ends_at' => $calendar->nextWeekStart($start),
            'voting_closes_at' => $calendar->votingClosesAt($start),
        ];
    }
}
