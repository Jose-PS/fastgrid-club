<?php

declare(strict_types=1);

namespace App\Enums;

enum TuningType: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
}
