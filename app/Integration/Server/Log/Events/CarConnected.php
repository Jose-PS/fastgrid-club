<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class CarConnected implements LogEvent
{
    public function __construct(
        public string $steamId,
        public string $runtimeName,
        public string $carId,
        public CarbonImmutable $at,
    ) {}
}
