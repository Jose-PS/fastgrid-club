<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Data;

use Carbon\CarbonImmutable;

final readonly class SeenDriver
{
    public function __construct(
        public string $steamId,
        public ?string $name,
        public CarbonImmutable $firstSeen,
        public CarbonImmutable $lastSeen,
    ) {}
}
