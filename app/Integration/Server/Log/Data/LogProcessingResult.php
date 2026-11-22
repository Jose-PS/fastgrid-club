<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Data;

final readonly class LogProcessingResult
{
    /**
     * @param  list<AttributedLap>  $laps
     * @param  list<LoggedSession>  $sessions
     * @param  list<SeenDriver>  $drivers
     */
    public function __construct(
        public array $laps,
        public array $sessions,
        public array $drivers,
        public LogMetrics $metrics,
    ) {}
}
