<?php

declare(strict_types=1);

namespace App\Integration\Server\Log;

use Carbon\CarbonImmutable;

final class LogProcessorState
{
    private const FORMAT = 'Y-m-d H:i:s.v';

    /**
     * @param  array<string, array{steam_id: string, name: ?string, runtime_name: ?string}>  $cars
     * @param  array<string, int>  $lapNumbers
     * @param  array{type: string, started_at: string, hash: string}|null  $session
     */
    public function __construct(
        public array $cars = [],
        public array $lapNumbers = [],
        public ?array $session = null,
        public ?string $phase = null,
        public ?CarbonImmutable $lastAt = null,
        public bool $restartPending = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $lastAt = isset($data['last_at']) && is_string($data['last_at'])
            ? CarbonImmutable::createFromFormat(self::FORMAT, $data['last_at'], 'UTC')
            : null;

        $session = $data['session'] ?? null;
        $validSession = is_array($session)
            && is_string($session['type'] ?? null)
            && is_string($session['started_at'] ?? null)
            && is_string($session['hash'] ?? null);

        return new self(
            cars: (array) ($data['cars'] ?? []),
            lapNumbers: array_map('intval', (array) ($data['lap_numbers'] ?? [])),
            session: $validSession ? ['type' => $session['type'], 'started_at' => $session['started_at'], 'hash' => $session['hash']] : null,
            phase: isset($data['phase']) ? (string) $data['phase'] : null,
            lastAt: $lastAt ?: null,
            restartPending: (bool) ($data['restart_pending'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cars' => $this->cars,
            'lap_numbers' => $this->lapNumbers,
            'session' => $this->session,
            'phase' => $this->phase,
            'last_at' => $this->lastAt?->setTimezone('UTC')->format(self::FORMAT),
            'restart_pending' => $this->restartPending,
        ];
    }
}
