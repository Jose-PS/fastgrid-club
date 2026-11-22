<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

use App\Enums\InitialGrip;
use App\Enums\ServerType;
use App\Enums\TrackMode;
use App\Enums\TuningType;
use App\Enums\WeatherBehaviour;
use App\Enums\WeatherType;

final readonly class EditableConfig
{
    /**
     * @param  list<string>  $carRefs
     * @param  array<string, array<string, mixed>>  $sessions
     */
    public function __construct(
        public ?string $serverName,
        public ?int $maxPlayers,
        public ?bool $cycleEnabled,
        public ?ServerType $serverType,
        public ?TuningType $tuningType,
        #[\SensitiveParameter] public ?string $driverPassword,
        #[\SensitiveParameter] public ?string $adminPassword,
        #[\SensitiveParameter] public ?string $spectatorPassword,
        public ?TrackMode $mode,
        public ?string $trackRef,
        public ?WeatherType $weather,
        public ?WeatherBehaviour $weatherBehaviour,
        public ?InitialGrip $initialGrip,
        public array $carRefs,
        public array $sessions,
    ) {}
}
