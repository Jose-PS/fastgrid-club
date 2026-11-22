<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class DriverNamed implements LogEvent
{
    public function __construct(
        public string $carId,
        public string $steamId,
        public string $name,
        public CarbonImmutable $at,
    ) {}
}
