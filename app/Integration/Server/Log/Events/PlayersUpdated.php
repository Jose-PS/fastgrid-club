<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class PlayersUpdated implements LogEvent
{
    public function __construct(
        public int $count,
        public CarbonImmutable $at,
    ) {}
}
