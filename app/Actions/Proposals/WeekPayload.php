<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Week;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;

class WeekPayload
{
    public function __construct(private readonly WeekCalendar $calendar) {}

    /**
     * @return array{id: int, startsAt: string, endsAt: string, votingClosesAt: string, zone: string, status: string, isOpen: bool}
     */
    public function make(Week $week, CarbonImmutable $now): array
    {
        return [
            'id' => $week->id,
            'startsAt' => $this->iso(CarbonImmutable::instance($week->starts_at)),
            'endsAt' => $this->iso(CarbonImmutable::instance($week->ends_at)),
            'votingClosesAt' => $this->iso(CarbonImmutable::instance($week->voting_closes_at)),
            'zone' => CarbonImmutable::instance($week->voting_closes_at)->setTimezone($this->calendar->timezone())->format('T'),
            'status' => $week->status->value,
            'isOpen' => $week->acceptsVotesAt($now),
        ];
    }

    public function iso(CarbonImmutable $instant): string
    {
        return $instant->setTimezone($this->calendar->timezone())->format('c');
    }
}
