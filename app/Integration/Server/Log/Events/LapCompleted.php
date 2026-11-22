<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class LapCompleted implements LogEvent
{
    public function __construct(
        public string $carId,
        public int $lapMs,
        public CarbonImmutable $at,
        public string $rawLine,
    ) {}
}
