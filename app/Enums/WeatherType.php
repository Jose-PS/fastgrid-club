<?php

declare(strict_types=1);

namespace App\Enums;

enum WeatherType: string
{
    case Clear = 'clear';
    case ScatteredClouds = 'scattered_clouds';
    case BrokenClouds = 'broken_clouds';
    case Overcast = 'overcast';
    case Drizzle = 'drizzle';
    case Rain = 'rain';
    case HeavyRain = 'heavy_rain';
    case Damp = 'damp';
}
