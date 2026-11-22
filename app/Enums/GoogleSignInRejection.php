<?php

declare(strict_types=1);

namespace App\Enums;

enum GoogleSignInRejection
{
    case EmailNotVerified;
    case EmailInUse;
}
