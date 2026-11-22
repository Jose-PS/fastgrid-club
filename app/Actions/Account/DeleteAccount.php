<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Events\AccountDeleted;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteAccount
{
    public function handle(User $user, bool $hideLaps): void
    {
        DB::transaction(function () use ($user, $hideLaps): void {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $steamId = $user->steamIdentity()->value('provider_id');

            if ($user->email !== null) {
                LoginLink::query()->where('email', $user->email)->delete();
            }

            $user->delete();

            AccountDeleted::dispatch($user->id, is_string($steamId) ? $steamId : null, $hideLaps);

            Log::info('account.deleted', [
                'user_id' => $user->id,
                'had_steam' => is_string($steamId),
                'hide_laps' => $hideLaps,
            ]);
        });
    }
}
