<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerDriverType: string
{
    case ZinoPanel = 'zino_panel';
    case Fake = 'fake';
}
