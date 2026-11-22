<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SteamLinked;
use App\Events\SteamUnlinked;
use App\Models\Driver;

class SyncDriverUserLink
{
    public function handleSteamLinked(SteamLinked $event): void
    {
        Driver::query()->where('steam_id', $event->steamId)->update(['user_id' => $event->userId]);
    }

    public function handleSteamUnlinked(SteamUnlinked $event): void
    {
        Driver::query()
            ->where('steam_id', $event->steamId)
            ->where('user_id', $event->userId)
            ->update(['user_id' => null]);
    }
}
