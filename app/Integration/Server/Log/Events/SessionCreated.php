<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Events;

use App\Integration\Server\Log\SessionType;
use Carbon\CarbonImmutable;

final readonly class SessionCreated implements LogEvent
{
    public function __construct(
        public SessionType $type,
        public CarbonImmutable $at,
        public string $rawLine,
    ) {}
}
