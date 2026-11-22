<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $now = now();

        User::query()->whereKey($event->user->getKey())->toBase()->update(['last_login_at' => $now]);
        $event->user->setAttribute('last_login_at', $now);
        $event->user->syncOriginalAttribute('last_login_at');
    }
}
