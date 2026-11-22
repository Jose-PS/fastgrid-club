<?php

declare(strict_types=1);

namespace App\Enums;

enum ConfigSource: string
{
    case Proposal = 'proposal';
    case Admin = 'admin';
    case Fallback = 'fallback';
}
