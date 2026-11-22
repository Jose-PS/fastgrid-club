<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerSession: string
{
    case Practice = 'practice';
    case Qualifying = 'qualy';
    case Race = 'race';
}
