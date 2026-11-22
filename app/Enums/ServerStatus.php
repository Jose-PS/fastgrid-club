<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Unavailable = 'unavailable';
}
