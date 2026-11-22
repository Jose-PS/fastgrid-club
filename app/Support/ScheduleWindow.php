<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RaceDurationType;
use App\Enums\WeatherType;
use Carbon\CarbonImmutable;

final readonly class ScheduleWindow
{
    public function __construct(
        public CarbonImmutable $weekStartsAt,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $key,
        public int $versionId,
        public ?int $practiceMinutes,
        public ?int $qualifyingMinutes,
        public ?RaceDurationType $raceDurationType,
        public ?int $raceMinutes,
        public ?int $raceLaps,
        public int $startHour,
        public WeatherType $weather,
    ) {}

    public function isRaceWeekend(): bool
    {
        return $this->qualifyingMinutes !== null || $this->raceDurationType !== null;
    }
}
