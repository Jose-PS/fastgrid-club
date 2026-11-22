<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class LiveDriver
{
    public function __construct(
        public string $carRef,
        public string $name,
        public ?int $number,
        public int $laps,
        public ?int $lastLapMs,
        public ?int $bestLapMs,
    ) {}
}
