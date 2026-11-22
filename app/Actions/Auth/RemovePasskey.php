<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;

class RemovePasskey
{
    public function __construct(
        private SignInMethods $signInMethods,
        private DeletePasskey $deletePasskey,
    ) {}

    public function handle(User $user, Passkey $passkey): bool
    {
        return DB::transaction(function () use ($user, $passkey): bool {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $this->signInMethods->canRemove($user, $passkey)) {
                return false;
            }

            ($this->deletePasskey)($user, $passkey);

            return true;
        });
    }
}
