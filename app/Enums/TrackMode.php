<?php

declare(strict_types=1);

namespace App\Enums;

enum TrackMode: string
{
    case Practice = 'practice';
    case RaceWeekend = 'race_weekend';
}
