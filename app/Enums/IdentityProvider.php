<?php

declare(strict_types=1);

namespace App\Enums;

enum IdentityProvider: string
{
    case Steam = 'steam';
    case Google = 'google';
}
