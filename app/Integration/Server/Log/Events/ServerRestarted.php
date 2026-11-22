<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use Carbon\CarbonImmutable;

final readonly class ServerRestarted implements LogEvent
{
    public function __construct(
        public ?CarbonImmutable $at,
    ) {}
}
