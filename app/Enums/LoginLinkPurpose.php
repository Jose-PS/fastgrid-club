<?php

declare(strict_types=1);

namespace App\Enums;

enum LoginLinkPurpose: string
{
    case Login = 'login';
    case Confirmation = 'confirmation';
    case EmailChange = 'email_change';
}
