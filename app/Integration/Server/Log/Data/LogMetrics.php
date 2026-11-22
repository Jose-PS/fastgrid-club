<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Data;

final readonly class LogMetrics
{
    public function __construct(
        public int $lines,
        public int $laps,
        public int $lapsDiscarded,
        public int $lapsRejected,
        public int $restarts,
        public ?int $playersCount,
    ) {}
}
