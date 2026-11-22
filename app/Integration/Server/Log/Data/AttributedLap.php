<?php

declare(strict_types=1);

namespace App\Integration\Server\Log\Data;

use Carbon\CarbonImmutable;

final readonly class AttributedLap
{
    public function __construct(
        public string $steamId,
        public ?string $driverName,
        public ?string $carRuntimeName,
        public int $lapMs,
        public ?int $lapNumber,
        public bool $isValid,
        public CarbonImmutable $recordedAt,
        public ?string $sessionKey,
        public string $sourceHash,
    ) {}
}
