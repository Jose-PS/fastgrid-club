<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerType: string
{
    case Ranked = 'ranked';
    case Unranked = 'unranked';
}
