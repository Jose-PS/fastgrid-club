<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LogoutOtherSessions
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'session_version' => $locked->session_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            $user->forceFill([
                'session_version' => $locked->session_version,
                'remember_token' => $locked->remember_token,
            ])->syncOriginal();
        });
    }
}
