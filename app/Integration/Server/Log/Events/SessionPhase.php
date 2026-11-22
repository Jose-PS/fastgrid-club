<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class SessionPhase implements LogEvent
{
    public function __construct(
        public string $phase,
        public CarbonImmutable $at,
    ) {}
}
