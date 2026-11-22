<?php

declare(strict_types=1);

namespace App\Integration\Server\Log;

use App\Integration\Server\Log\Data\AttributedLap;
use App\Integration\Server\Log\Data\LoggedSession;
use App\Integration\Server\Log\Data\LogMetrics;
use App\Integration\Server\Log\Data\LogProcessingResult;
use App\Integration\Server\Log\Data\SeenDriver;
use App\Integration\Server\Log\Events\CarConnected;
use App\Integration\Server\Log\Events\CarCreated;
use App\Integration\Server\Log\Events\CarDestroyed;
use App\Integration\Server\Log\Events\DriverNamed;
use App\Integration\Server\Log\Events\LapCompleted;
use App\Integration\Server\Log\Events\LapRejected;
use App\Integration\Server\Log\Events\PlayersUpdated;
use App\Integration\Server\Log\Events\ServerRestarted;
use App\Integration\Server\Log\Events\SessionCreated;
use App\Integration\Server\Log\Events\SessionPhase;
use App\Integration\Server\Log\Events\SplitCompleted;
use Carbon\CarbonImmutable;

final class LogProcessor
{
    private LogProcessorState $state;

    /** @var list<AttributedLap> */
    private array $laps = [];

    /** @var array<string, LoggedSession> */
    private array $sessions = [];

    /** @var array<string, array{name: ?string, first: CarbonImmutable, last: CarbonImmutable}> */
    private array $drivers = [];

    private int $lines = 0;

    private int $lapsDiscarded = 0;

    private int $lapsRejected = 0;

    private int $restarts = 0;

    private ?int $playersCount = null;

    public function __construct(private readonly LogLineParser $parser = new LogLineParser, ?LogProcessorState $state = null)
    {
        $this->state = $state ?? new LogProcessorState;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function importState(array $state): void
    {
        $this->state = LogProcessorState::fromArray($state);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportState(): array
    {
        return $this->state->toArray();
    }

    /**
     * @param  iterable<string>  $lines
     */
    public function process(iterable $lines): LogProcessingResult
    {
        $this->resetRun();

        foreach ($lines as $line) {
            $this->lines++;
            $event = $this->parser->parse($line);

            if ($event !== null) {
                $this->apply($event);
            }
        }

        return new LogProcessingResult(
            $this->laps,
            array_values($this->sessions),
            $this->seenDrivers(),
            new LogMetrics($this->lines, count($this->laps), $this->lapsDiscarded, $this->lapsRejected, $this->restarts, $this->playersCount),
        );
    }

    private function resetRun(): void
    {
        $this->laps = [];
        $this->sessions = [];
        $this->drivers = [];
        $this->lines = 0;
        $this->lapsDiscarded = 0;
        $this->lapsRejected = 0;
        $this->restarts = 0;
        $this->playersCount = null;
    }

    private function apply(object $event): void
    {
        if (! $event instanceof ServerRestarted) {
            $this->state->restartPending = false;
        }

        match (true) {
            $event instanceof CarCreated => $this->onCarCreated($event),
            $event instanceof CarConnected => $this->onCarConnected($event),
            $event instanceof CarDestroyed => $this->onCarDestroyed($event),
            $event instanceof DriverNamed => $this->onDriverNamed($event),
            $event instanceof SplitCompleted => $this->onSplit($event),
            $event instanceof LapCompleted => $this->onLap($event),
            $event instanceof LapRejected => $this->onRejected($event),
            $event instanceof SessionCreated => $this->onSessionCreated($event),
            $event instanceof SessionPhase => $this->onPhase($event),
            $event instanceof PlayersUpdated => $this->onPlayers($event),
            $event instanceof ServerRestarted => $this->onRestart($event),
            default => null,
        };

        if (isset($event->at)) {
            $this->state->lastAt = $event->at;
        }
    }

    private function onCarCreated(CarCreated $event): void
    {
        $previous = $this->state->cars[$event->carId] ?? null;
        $sameDriver = $previous !== null && $previous['steam_id'] === $event->steamId;

        $this->state->cars[$event->carId] = [
            'steam_id' => $event->steamId,
            'name' => $event->name,
            'runtime_name' => $sameDriver ? $previous['runtime_name'] : null,
        ];

        $this->see($event->steamId, $event->name, $event->at);
    }

    private function onCarConnected(CarConnected $event): void
    {
        $previous = $this->state->cars[$event->carId] ?? null;
        $sameDriver = $previous !== null && $previous['steam_id'] === $event->steamId;

        $this->state->cars[$event->carId] = [
            'steam_id' => $event->steamId,
            'name' => $sameDriver ? $previous['name'] : null,
            'runtime_name' => $event->runtimeName,
        ];

        $this->see($event->steamId, $sameDriver ? $previous['name'] : null, $event->at);
    }

    private function onCarDestroyed(CarDestroyed $event): void
    {
        if (! $event->byConnection) {
            return;
        }

        $entry = $this->state->cars[$event->carId] ?? null;

        if ($entry !== null && $entry['steam_id'] === $event->steamId) {
            unset($this->state->cars[$event->carId], $this->state->lapNumbers[$event->carId]);
        }

        $this->see($event->steamId, null, $event->at);
    }

    private function onDriverNamed(DriverNamed $event): void
    {
        $entry = $this->state->cars[$event->carId] ?? null;

        if ($entry === null || $entry['steam_id'] !== $event->steamId || ($entry['name'] ?? '') !== '') {
            return;
        }

        $this->state->cars[$event->carId]['name'] = $event->name;
        $this->see($event->steamId, $event->name, $event->at);
    }

    private function onSplit(SplitCompleted $event): void
    {
        $this->state->lapNumbers[$event->carId] = $event->lapNumber;
    }

    private function onLap(LapCompleted $event): void
    {
        $entry = $this->state->cars[$event->carId] ?? null;
        $lapNumber = $this->state->lapNumbers[$event->carId] ?? null;
        unset($this->state->lapNumbers[$event->carId]);

        if ($entry === null) {
            $this->lapsDiscarded++;

            return;
        }

        $this->laps[] = new AttributedLap(
            $entry['steam_id'],
            $entry['name'],
            $entry['runtime_name'],
            $event->lapMs,
            $lapNumber,
            true,
            $event->at,
            $this->state->session['hash'] ?? null,
            hash('sha256', $event->rawLine),
        );

        $this->see($entry['steam_id'], null, $event->at);
    }

    private function onRejected(LapRejected $event): void
    {
        unset($this->state->lapNumbers[$event->carId]);
        $this->lapsRejected++;
    }

    private function onSessionCreated(SessionCreated $event): void
    {
        $this->closeSession($event->at);

        $hash = hash('sha256', $event->rawLine);
        $this->state->session = [
            'type' => $event->type->value,
            'started_at' => $event->at->format('Y-m-d H:i:s.v'),
            'hash' => $hash,
        ];
        $this->state->phase = null;
        $this->state->lapNumbers = [];
        $this->sessions[$hash] = new LoggedSession($event->type, $event->at, null, $hash);
    }

    private function onPhase(SessionPhase $event): void
    {
        $this->state->phase = $event->phase;

        if ($event->phase === 'Ended') {
            $this->closeSession($event->at);
        }
    }

    private function onPlayers(PlayersUpdated $event): void
    {
        $this->playersCount = $event->count;
    }

    private function onRestart(ServerRestarted $event): void
    {
        if (! $this->state->restartPending) {
            $this->restarts++;
        }

        $this->closeSession($event->at ?? $this->state->lastAt);
        $this->state->cars = [];
        $this->state->lapNumbers = [];
        $this->state->phase = null;
        $this->state->restartPending = true;
    }

    private function closeSession(?CarbonImmutable $at): void
    {
        $session = $this->state->session;

        if ($session === null) {
            return;
        }

        $startedAt = CarbonImmutable::createFromFormat('Y-m-d H:i:s.v', $session['started_at'], 'UTC');
        $this->sessions[$session['hash']] = new LoggedSession(
            SessionType::from($session['type']),
            $startedAt,
            $at ?? $startedAt,
            $session['hash'],
        );
        $this->state->session = null;
    }

    private function see(string $steamId, ?string $name, CarbonImmutable $at): void
    {
        $known = $this->drivers[$steamId] ?? null;

        $this->drivers[$steamId] = [
            'name' => $name ?? $known['name'] ?? null,
            'first' => $known['first'] ?? $at,
            'last' => $at,
        ];
    }

    /**
     * @return list<SeenDriver>
     */
    private function seenDrivers(): array
    {
        $drivers = [];

        foreach ($this->drivers as $steamId => $driver) {
            $drivers[] = new SeenDriver((string) $steamId, $driver['name'], $driver['first'], $driver['last']);
        }

        return $drivers;
    }
}
