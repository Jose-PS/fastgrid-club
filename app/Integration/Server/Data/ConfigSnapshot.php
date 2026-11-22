<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

use App\Enums\RaceDurationType;
use App\Enums\TrackMode;

final readonly class ConfigSnapshot
{
    /**
     * @param  list<string>  $carRefs
     */
    public function __construct(
        public ?TrackMode $mode,
        public ?string $trackRef,
        public array $carRefs,
        public ?string $serverName,
        public ?int $maxPlayers,
        public ?int $qualifyingSeconds,
        public ?int $raceSeconds,
        public ?int $practiceSeconds,
        public ?bool $cycleEnabled,
        public ?RaceDurationType $raceDurationType = null,
        public ?int $raceLaps = null,
    ) {}
}
