<?php

declare(strict_types=1);

namespace App\Integration\Server\Log;

enum SessionType: string
{
    case Practice = 'practice';
    case Qualifying = 'qualifying';
    case Race = 'race';
}
