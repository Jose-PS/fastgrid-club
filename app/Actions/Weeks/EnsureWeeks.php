<?php

declare(strict_types=1);

namespace App\Actions\Weeks;

use App\Enums\WeekStatus;
use App\Models\Server;
use App\Models\Week;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;

class EnsureWeeks
{
    public function __construct(private readonly WeekCalendar $calendar, private readonly ResolveWeek $resolveWeek) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $created = 0;

        Server::query()->where('is_active', true)->each(function (Server $server) use ($now, &$created): void {
            $this->resolveWeek->due($now, $server->id);
            $this->advance($server, $now);

            $currentStart = $this->calendar->weekStartFor($now);
            $created += $this->ensure($server, $currentStart, WeekStatus::Active);
            $created += $this->ensure($server, $this->calendar->nextWeekStart($currentStart), WeekStatus::Voting);
        });

        return $created;
    }

    private function advance(Server $server, CarbonImmutable $now): void
    {
        Week::query()
            ->where('server_id', $server->id)
            ->where('status', WeekStatus::Scheduled)
            ->where('starts_at', '<=', $now)
            ->update(['status' => WeekStatus::Active]);

        Week::query()
            ->where('server_id', $server->id)
            ->where('status', WeekStatus::Active)
            ->where('ends_at', '<=', $now)
            ->update(['status' => WeekStatus::Finished]);
    }

    private function ensure(Server $server, CarbonImmutable $startsAt, WeekStatus $status): int
    {
        $exists = Week::query()
            ->where('server_id', $server->id)
            ->where('starts_at', $startsAt)
            ->exists();

        if ($exists) {
            return 0;
        }

        $week = Week::query()->createOrFirst(
            ['server_id' => $server->id, 'starts_at' => $startsAt],
            [
                'ends_at' => $this->calendar->nextWeekStart($startsAt),
                'voting_closes_at' => $this->calendar->votingClosesAt($startsAt),
                'status' => $status,
            ],
        );

        return $week->wasRecentlyCreated ? 1 : 0;
    }
}
