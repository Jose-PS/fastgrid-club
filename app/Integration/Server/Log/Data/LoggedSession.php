<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Data;

use App\Integration\Server\Log\SessionType;
use Carbon\CarbonImmutable;

final readonly class LoggedSession
{
    public function __construct(
        public SessionType $type,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public string $sourceLineHash,
    ) {}
}
