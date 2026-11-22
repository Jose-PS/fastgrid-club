<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

use App\Enums\TrackMode;

final readonly class CatalogTrack
{
    public function __construct(
        public TrackMode $mode,
        public string $trackRef,
        public string $display,
        public string $track,
        public string $layout,
        public string $eventName,
        public int $lengthM,
        public int $maxPitSlot,
    ) {}
}
