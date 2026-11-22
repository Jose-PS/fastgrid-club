<?php

declare(strict_types=1);

namespace App\Enums;

enum SteamLinkResult
{
    case Linked;
    case AlreadyLinked;
    case UserAlreadyHasSteam;
    case SteamOwnedByAnotherUser;
}
