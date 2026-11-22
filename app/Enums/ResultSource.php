<?php

declare(strict_types=1);

namespace App\Enums;

enum ResultSource: string
{
    case Server = 'server';
    case Log = 'log';
}
