<?php

declare(strict_types=1);

namespace App\Enums;

enum ConfigApplicationStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Failed = 'failed';
}
