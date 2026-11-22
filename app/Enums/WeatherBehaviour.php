<?php

declare(strict_types=1);

namespace App\Enums;

enum WeatherBehaviour: string
{
    case Static = 'static';
    case Dynamic = 'dynamic';
}
