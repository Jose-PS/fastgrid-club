<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class CarCreated implements LogEvent
{
    public function __construct(
        public string $carId,
        public string $steamId,
        public string $name,
        public CarbonImmutable $at,
    ) {}
}
