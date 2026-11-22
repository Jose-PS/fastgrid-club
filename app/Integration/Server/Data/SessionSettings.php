<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class SessionSettings
{
    public function __construct(
        public ?int $hour = null,
        public ?int $minute = null,
        public ?int $timeMultiplier = null,
        public ?int $maxWaitToBox = null,
        public ?int $overtimeWaitingNextSession = null,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return array_filter([
            'hour' => $this->hour,
            'max_wait_to_box' => $this->maxWaitToBox,
            'minute' => $this->minute,
            'overtime_waiting_next_session' => $this->overtimeWaitingNextSession,
            'time_multiplier' => $this->timeMultiplier,
        ], fn (?int $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::intOrNull($data['hour'] ?? null),
            self::intOrNull($data['minute'] ?? null),
            self::intOrNull($data['time_multiplier'] ?? null),
            self::intOrNull($data['max_wait_to_box'] ?? null),
            self::intOrNull($data['overtime_waiting_next_session'] ?? null),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
