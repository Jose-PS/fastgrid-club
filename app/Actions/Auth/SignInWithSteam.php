<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\IdentityProvider;
use App\Events\SteamLinked;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SignInWithSteam
{
    public function __construct(private readonly SteamPilotName $pilotName) {}

    public function handle(SocialiteUser $steamUser): User
    {
        $steamId = (string) $steamUser->getId();

        $identity = $this->findIdentity($steamId);

        if ($identity !== null) {
            $this->refreshSteamName($identity, $steamUser);

            return $identity->user;
        }

        try {
            return DB::transaction(function () use ($steamUser, $steamId): User {
                $name = $this->pilotName->for($steamUser);

                $user = User::query()->create([
                    'name' => $name,
                    'locale' => app()->getLocale(),
                ]);

                $user->identities()->create([
                    'provider' => IdentityProvider::Steam,
                    'provider_id' => $steamId,
                    'display_name' => $name,
                    'avatar_url' => $this->pilotName->avatar($steamUser),
                    'linked_at' => now(),
                ]);

                SteamLinked::dispatch($user->id, $steamId);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $identity = $this->findIdentity($steamId);

            if ($identity === null) {
                throw $exception;
            }

            return $identity->user;
        }
    }

    private function refreshSteamName(UserIdentity $identity, SocialiteUser $steamUser): void
    {
        $steamName = $this->pilotName->fromSteam($steamUser);

        if ($steamName === null) {
            return;
        }

        $user = $identity->user;

        if ($user->name === $this->pilotName->automatic($steamUser)) {
            $user->name = $steamName;
        }

        $identity->display_name = $steamName;

        DB::transaction(function () use ($user, $identity): void {
            $user->save();
            $identity->save();
        });
    }

    private function findIdentity(string $steamId): ?UserIdentity
    {
        return UserIdentity::query()
            ->where('provider', IdentityProvider::Steam)
            ->where('provider_id', $steamId)
            ->first();
    }
}
