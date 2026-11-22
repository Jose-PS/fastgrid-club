<?php

declare(strict_types=1);

namespace App\Enums;

enum RaceDurationType: string
{
    case Time = 'time';
    case Laps = 'laps';
}
