<?php

declare(strict_types=1);

namespace App\Enums;

enum GoogleLinkResult
{
    case Linked;
    case AlreadyLinked;
    case UserAlreadyHasGoogle;
    case GoogleOwnedByAnotherUser;
}
