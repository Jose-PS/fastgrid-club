<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Alerts\CloseAlert;
use App\Actions\Alerts\OpenAlert;
use App\Actions\Integration\RecordIntegrationFailure;
use App\Actions\Integration\RecordIntegrationSuccess;
use App\Actions\Stats\RecordBestLaps;
use App\Actions\Stats\ResultsSource;
use App\Enums\AlertType;
use App\Enums\IdentityProvider;
use App\Enums\IntegrationEventType;
use App\Enums\TrackMode;
use App\Integration\Server\Data\LogEvent;
use App\Integration\Server\Log\Data\LogProcessingResult;
use App\Integration\Server\Log\LogProcessor;
use App\Integration\Server\Log\SessionType;
use App\Integration\Server\ServerCircuitBreaker;
use App\Integration\Server\ServerDriverException;
use App\Integration\Server\ServerDriverManager;
use App\Models\Car;
use App\Models\Driver;
use App\Models\Lap;
use App\Models\LogCursor;
use App\Models\Server;
use App\Models\ServerSession;
use App\Models\UserIdentity;
use App\Models\Week;
use App\Support\WeekCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

class IngestServerLogs
{
    public const PAGE_SIZE = 1000;

    public const MAX_PAGES = 20;

    public const SLOW_INTERVAL_SECONDS = 60;

    public const GAP_ALERT_HOURS = 24;

    private const LOCK_SECONDS = 120;

    private const INSERT_CHUNK = 500;

    private const UNEXPECTED_FAILURE = 'unexpected error while ingesting the server log';

    public function __construct(
        private readonly ServerDriverManager $drivers,
        private readonly ServerCircuitBreaker $breaker,
        private readonly RecordIntegrationFailure $recordFailure,
        private readonly RecordIntegrationSuccess $recordSuccess,
        private readonly RecordBestLaps $recordBestLaps,
        private readonly ResultsSource $results,
        private readonly WeekCalendar $calendar,
        private readonly OpenAlert $openAlert,
        private readonly CloseAlert $closeAlert,
    ) {}

    public static function lastAttemptKey(Server $server): string
    {
        return "server:{$server->id}:ingest:last_attempt_at";
    }

    public static function metricsKey(Server $server): string
    {
        return "server:{$server->id}:ingest:metrics";
    }

    public function handle(Server $server, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->shouldSkip($server, $now)) {
            return false;
        }

        $lock = Cache::lock("server:{$server->id}:ingest", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            Cache::forever(self::lastAttemptKey($server), $now->getTimestamp());

            $this->ingest($server, $now);
        } catch (ServerDriverException $exception) {
            $this->recordFailure->handle($server, IntegrationEventType::IngestFailed, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure->handle($server, IntegrationEventType::IngestFailed, self::UNEXPECTED_FAILURE);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * @return array{lines: int, laps: int}
     */
    public function backfill(Server $server, CarbonImmutable $since): array
    {
        $lock = Cache::lock("server:{$server->id}:ingest", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException('the ingest lock is held by another process');
        }

        try {
            $driver = $this->drivers->for($server);
            $threshold = $since->utc()->format('Y-m-d H:i:s.v');
            $selected = [];
            $started = false;
            $afterId = 0;

            do {
                $page = $driver->logEvents($afterId, self::PAGE_SIZE);

                foreach ($page->events as $event) {
                    if (! $started && $this->isAtOrAfter($event->line, $threshold)) {
                        $started = true;
                    }

                    if ($started) {
                        $selected[] = $event->line;
                    }

                    $afterId = max($afterId, $event->id);
                }
            } while (count($page->events) >= self::PAGE_SIZE);

            $before = Lap::query()->where('server_id', $server->id)->count();

            DB::transaction(function () use ($server, $selected): void {
                $this->persist($server, (new LogProcessor)->process($selected));
            });

            return [
                'lines' => count($selected),
                'laps' => Lap::query()->where('server_id', $server->id)->count() - $before,
            ];
        } finally {
            $lock->release();
        }
    }

    private function isAtOrAfter(string $line, string $threshold): bool
    {
        return preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\]/', $line, $matches) === 1 && $matches[1] >= $threshold;
    }

    private function ingest(Server $server, CarbonImmutable $now): void
    {
        $driver = $this->drivers->for($server);
        $cursor = LogCursor::query()->where('server_id', $server->id)->first();
        $lastEventId = $cursor?->last_event_id;
        $afterId = $lastEventId ?? 0;
        $state = $cursor === null || $lastEventId === null ? [] : $cursor->processor_state;

        $gap = false;
        $pages = 0;
        $totals = ['lines' => 0, 'laps' => 0, 'laps_discarded' => 0, 'laps_rejected' => 0, 'restarts' => 0, 'players' => null];

        while ($pages < self::MAX_PAGES) {
            $page = $driver->logEvents($afterId, self::PAGE_SIZE);

            if ($page->events === []) {
                break;
            }

            $newLastId = $page->events[array_key_last($page->events)]->id;

            if ($newLastId <= $afterId) {
                break;
            }

            if ($pages === 0 && $lastEventId !== null && ($page->firstId ?? $page->events[0]->id) > $lastEventId + 1) {
                $gap = true;
            }

            $lines = array_map(static fn (LogEvent $event): string => $event->line, $page->events);

            $result = DB::transaction(function () use ($server, $cursor, $lines, $state, $newLastId, $now, $gap, &$newState): LogProcessingResult {
                $processor = new LogProcessor;

                if ($state !== []) {
                    $processor->importState($state);
                }

                $result = $processor->process($lines);

                $this->persist($server, $result);

                $newState = $processor->exportState();

                LogCursor::query()->updateOrCreate(['server_id' => $server->id], [
                    'window_hashes' => [],
                    'last_event_id' => $newLastId,
                    'processor_state' => $newState,
                    'last_read_at' => $now,
                    'last_gap_at' => $gap ? $now : $cursor?->last_gap_at,
                ]);

                return $result;
            });

            $state = $newState;
            $afterId = $newLastId;
            $pages++;

            $totals['lines'] += $result->metrics->lines;
            $totals['laps'] += $result->metrics->laps;
            $totals['laps_discarded'] += $result->metrics->lapsDiscarded;
            $totals['laps_rejected'] += $result->metrics->lapsRejected;
            $totals['restarts'] += $result->metrics->restarts;
            $totals['players'] = $result->metrics->playersCount;

            if (count($page->events) < self::PAGE_SIZE) {
                break;
            }
        }

        if ($pages === 0) {
            $this->recordSuccess->handle($server, IntegrationEventType::IngestFailed);

            return;
        }

        Cache::forever(self::metricsKey($server), ['at' => $now->toIso8601String(), ...$totals]);

        if ($gap) {
            $this->openAlert->handle(
                AlertType::LogGap,
                'admin.alerts.logGap',
                ['server' => $server->name],
                $server,
                $server->id,
            );
            $this->recordFailure->handle(
                $server,
                IntegrationEventType::LogGap,
                'log gap: the panel log starts after the last processed event; processed '.$totals['lines'].' lines in full',
            );
        } else {
            $this->recordSuccess->handle($server, IntegrationEventType::LogGap);

            if ($cursor?->last_gap_at !== null && $cursor->last_gap_at->lessThanOrEqualTo($now->subHours(self::GAP_ALERT_HOURS))) {
                $this->closeAlert->forSubject(AlertType::LogGap, $server);
            }
        }

        $this->recordSuccess->handle($server, IntegrationEventType::IngestFailed);
    }

    private function persist(Server $server, LogProcessingResult $result): void
    {
        $weeks = $this->weeksFor($server, $result);

        ['map' => $sessions, 'closedRaces' => $closedRaces] = $this->persistSessions($server, $result, $weeks);
        $driverIds = $this->persistDrivers($result);

        $carIds = Car::query()
            ->whereIn('runtime_name', array_values(array_unique(array_filter(array_map(
                static fn ($lap): ?string => $lap->carRuntimeName,
                $result->laps,
            )))))
            ->pluck('id', 'runtime_name');

        $rows = [];
        $countable = [];

        foreach ($result->laps as $lap) {
            $session = $lap->sessionKey === null ? null : ($sessions[$lap->sessionKey] ?? null);

            $rows[] = [
                'server_id' => $server->id,
                'server_session_id' => $session['id'] ?? null,
                'driver_id' => $driverIds[$lap->steamId],
                'car_id' => $lap->carRuntimeName === null ? null : ($carIds[$lap->carRuntimeName] ?? null),
                'track_id' => $this->circuitFor($this->weekAt($weeks, $lap->recordedAt), $session['type'] ?? null),
                'lap_ms' => $lap->lapMs,
                'lap_number' => $lap->lapNumber === null ? null : $lap->lapNumber + 1,
                'is_valid' => $lap->isValid,
                'recorded_at' => $this->format($lap->recordedAt),
                'source_hash' => $lap->sourceHash,
            ];
        }

        foreach ($rows as $row) {
            if ($row['track_id'] !== null && $row['car_id'] !== null && $row['is_valid'] !== false) {
                $countable[] = $row['source_hash'];
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            Lap::query()->insertOrIgnore($chunk);
        }

        foreach (array_chunk($countable, self::INSERT_CHUNK) as $hashes) {
            $this->recordBestLaps->handle(
                Lap::query()->where('server_id', $server->id)->whereIn('source_hash', $hashes)->get(),
            );
        }

        foreach ($closedRaces as $sessionId) {
            $this->results->rebuild(ServerSession::query()->findOrFail($sessionId));
        }
    }

    /**
     * @return Collection<int, Week>
     */
    private function weeksFor(Server $server, LogProcessingResult $result): Collection
    {
        $instants = [];

        foreach ($result->laps as $lap) {
            $instants[] = $lap->recordedAt;
        }

        foreach ($result->sessions as $session) {
            $instants[] = $session->startedAt;
        }

        if ($instants === []) {
            return new Collection;
        }

        $from = min($instants);
        $to = max($instants);

        return Week::query()
            ->where('server_id', $server->id)
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>', $from)
            ->with('track.pairedTrack')
            ->get();
    }

    /**
     * @param  Collection<int, Week>  $weeks
     */
    private function weekAt(Collection $weeks, CarbonImmutable $instant): ?Week
    {
        return $weeks->first(
            static fn (Week $week): bool => $week->starts_at <= $instant && $week->ends_at > $instant,
        );
    }

    private function circuitFor(?Week $week, ?SessionType $type): ?int
    {
        $track = $week?->track;

        if ($track === null) {
            return null;
        }

        $wanted = ($type ?? SessionType::Practice) === SessionType::Practice ? TrackMode::Practice : TrackMode::RaceWeekend;

        if ($track->mode === $wanted) {
            return $track->id;
        }

        return $track->pairedTrack?->mode === $wanted ? $track->paired_track_id : null;
    }

    /**
     * @param  Collection<int, Week>  $weeks
     * @return array{map: array<string, array{id: int, type: SessionType}>, closedRaces: list<int>}
     */
    private function persistSessions(Server $server, LogProcessingResult $result, Collection $weeks): array
    {
        $hashes = [];

        foreach ($result->sessions as $session) {
            $hashes[] = $session->sourceLineHash;
        }

        foreach ($result->laps as $lap) {
            if ($lap->sessionKey !== null) {
                $hashes[] = $lap->sessionKey;
            }
        }

        $existing = ServerSession::query()
            ->where('server_id', $server->id)
            ->whereIn('source_line_hash', array_values(array_unique($hashes)))
            ->get()
            ->keyBy('source_line_hash');

        $closedRaces = [];

        foreach ($result->sessions as $logged) {
            $model = $existing->get($logged->sourceLineHash);

            if ($model === null) {
                $week = $this->weekAt($weeks, $logged->startedAt);

                $created = ServerSession::query()->create([
                    'server_id' => $server->id,
                    'type' => $logged->type,
                    'started_at' => $logged->startedAt,
                    'ended_at' => $logged->endedAt,
                    'track_id' => $this->circuitFor($week, $logged->type),
                    'week_id' => $week?->id,
                    'cycle_index' => $this->cycleIndexFor($server, $logged->type, $logged->startedAt),
                    'source_line_hash' => $logged->sourceLineHash,
                ]);

                $existing->put($logged->sourceLineHash, $created);

                if ($logged->endedAt !== null && $logged->type === SessionType::Race) {
                    $closedRaces[] = $created->id;
                }

                continue;
            }

            if ($logged->endedAt !== null && $model->ended_at === null) {
                $model->forceFill(['ended_at' => $logged->endedAt])->save();

                if ($model->type === SessionType::Race) {
                    $closedRaces[] = $model->id;
                }
            }
        }

        $map = [];

        foreach ($existing as $hash => $model) {
            $map[(string) $hash] = ['id' => $model->id, 'type' => $model->type];
        }

        return ['map' => $map, 'closedRaces' => $closedRaces];
    }

    private function cycleIndexFor(Server $server, SessionType $type, CarbonImmutable $startedAt): ?int
    {
        if ($type === SessionType::Practice) {
            return null;
        }

        try {
            $window = $this->calendar->scheduleWindowAt($startedAt);
        } catch (LogicException) {
            return null;
        }

        return ServerSession::query()
            ->where('server_id', $server->id)
            ->where('type', SessionType::Race)
            ->where('started_at', '>=', $window->startsAt)
            ->where('started_at', '<', $startedAt)
            ->count();
    }

    /**
     * @return array<string, int>
     */
    private function persistDrivers(LogProcessingResult $result): array
    {
        $info = [];

        foreach ($result->drivers as $seen) {
            $info[$seen->steamId] = ['name' => $seen->name, 'first' => $seen->firstSeen, 'last' => $seen->lastSeen];
        }

        foreach ($result->laps as $lap) {
            $known = $info[$lap->steamId] ?? null;

            $info[$lap->steamId] = [
                'name' => $known['name'] ?? $lap->driverName,
                'first' => $known === null || $lap->recordedAt < $known['first'] ? $lap->recordedAt : $known['first'],
                'last' => $known === null || $lap->recordedAt > $known['last'] ? $lap->recordedAt : $known['last'],
            ];
        }

        if ($info === []) {
            return [];
        }

        $existing = Driver::query()->whereIn('steam_id', array_map('strval', array_keys($info)))->get()->keyBy('steam_id');

        $missing = array_values(array_diff(array_map('strval', array_keys($info)), $existing->keys()->all()));

        $userIds = $missing === [] ? collect() : UserIdentity::query()
            ->where('provider', IdentityProvider::Steam)
            ->whereIn('provider_id', $missing)
            ->pluck('user_id', 'provider_id');

        $ids = [];

        foreach ($info as $steamId => $data) {
            $steamId = (string) $steamId;
            $driver = $existing->get($steamId);

            if ($driver === null) {
                $driver = Driver::query()->create([
                    'steam_id' => $steamId,
                    'last_name' => $data['name'],
                    'user_id' => $userIds->get($steamId),
                    'first_seen_at' => $data['first'],
                    'last_seen_at' => $data['last'],
                ]);
            } else {
                $changes = [];

                if ($data['first'] < $driver->first_seen_at) {
                    $changes['first_seen_at'] = $data['first'];
                }

                if ($data['last'] >= $driver->last_seen_at) {
                    $changes['last_seen_at'] = $data['last'];

                    if ($data['name'] !== null) {
                        $changes['last_name'] = $data['name'];
                    }
                }

                if ($changes !== []) {
                    $driver->forceFill($changes)->save();
                }
            }

            $ids[$steamId] = $driver->id;
        }

        return $ids;
    }

    private function format(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s.vP');
    }

    private function shouldSkip(Server $server, CarbonImmutable $now): bool
    {
        if (! $this->breaker->isUnreachable($server)) {
            return false;
        }

        $lastAttempt = Cache::get(self::lastAttemptKey($server));

        return is_numeric($lastAttempt) && $now->getTimestamp() - (int) $lastAttempt < self::SLOW_INTERVAL_SECONDS;
    }
}
