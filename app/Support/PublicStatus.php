<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServerSession as EnumsServerSession;
use App\Enums\ServerStatus;
use App\Enums\WeekStatus;
use App\Integration\Server\Log\SessionType;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\Week;
use Carbon\CarbonImmutable;
use LogicException;

final class PublicStatus
{
    public function __construct(
        private readonly WeekCalendar $calendar,
        private readonly ServerSnapshot $snapshots,
        private readonly IngameName $ingameName,
    ) {}

    public function activeServer(): ?Server
    {
        return Server::query()->where('is_active', true)->orderBy('id')->first();
    }

    public function currentWeek(?Server $server, CarbonImmutable $now, bool $withCars = false): ?Week
    {
        if ($server === null) {
            return null;
        }

        return $server->weeks()
            ->with($withCars ? ['track', 'cars'] : ['track'])
            ->where('starts_at', $this->calendar->weekStartFor($now))
            ->first();
    }

    public function isConfigured(?Week $week): bool
    {
        return $week !== null && $week->track !== null && $week->status !== WeekStatus::Voting;
    }

    /**
     * @return array<string, mixed>
     */
    public function live(?Server $server, ?Week $week, CarbonImmutable $now): array
    {
        $snapshot = $server === null ? null : $this->snapshots->read($server);
        $status = $server === null ? ServerStatus::Unavailable : $this->snapshots->statusAt($snapshot, $now);
        $configured = $this->isConfigured($week);
        $maxPlayers = $server === null ? null : ($week->max_players ?? $server->capacityFor($week?->track));

        return [
            'status' => $status->value,
            'players' => $status === ServerStatus::Online ? $snapshot['players'] : null,
            'maxPlayers' => $maxPlayers,
            'track' => $configured ? $week->track->display : null,
            'session' => $configured ? $this->sessionNow($server, $status, $now) : null,
            'ingameName' => $server === null ? null : $this->ingameName->for($server, $week?->loadMissing('cars')),
            'serverNow' => $this->calendar->iso($now),
            'fetchedAt' => $snapshot === null ? null : $this->calendar->iso(CarbonImmutable::parse($snapshot['fetched_at'])),
        ];
    }

    private function sessionNow(?Server $server, ServerStatus $status, CarbonImmutable $now): ?string
    {
        if ($server !== null && $status === ServerStatus::Online) {
            $open = ServerSession::query()
                ->where('server_id', $server->id)
                ->whereNull('ended_at')
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();

            if ($open !== null) {
                return match ($open->type) {
                    SessionType::Practice => EnumsServerSession::Practice->value,
                    SessionType::Qualifying => EnumsServerSession::Qualifying->value,
                    SessionType::Race => EnumsServerSession::Race->value,
                };
            }
        }

        try {
            $window = $this->calendar->scheduleWindowAt($now);
        } catch (LogicException) {
            return null;
        }

        return $window->isRaceWeekend() ? null : EnumsServerSession::Practice->value;
    }
}
