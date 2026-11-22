<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Auth\SignInMethods;
use App\Enums\IdentityProvider;
use App\Events\SteamUnlinked;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\DB;

class UnlinkIdentity
{
    public function __construct(private readonly SignInMethods $signInMethods) {}

    public function handle(User $user, UserIdentity $identity): bool
    {
        return DB::transaction(function () use ($user, $identity): bool {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $this->signInMethods->canRemove($user, $identity)) {
                return false;
            }

            $identity->delete();

            if ($identity->provider === IdentityProvider::Steam) {
                SteamUnlinked::dispatch($user->id, $identity->provider_id);
            }

            return true;
        });
    }
}
