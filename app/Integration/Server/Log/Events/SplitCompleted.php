<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class SplitCompleted implements LogEvent
{
    public function __construct(
        public string $carId,
        public int $lapNumber,
        public int $splitIndex,
        public CarbonImmutable $at,
    ) {}
}
