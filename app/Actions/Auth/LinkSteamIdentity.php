<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\IdentityProvider;
use App\Enums\SteamLinkResult;
use App\Events\SteamLinked;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class LinkSteamIdentity
{
    public function __construct(private readonly SteamPilotName $pilotName) {}

    public function handle(User $user, SocialiteUser $steamUser): SteamLinkResult
    {
        $steamId = (string) $steamUser->getId();

        $owner = UserIdentity::query()
            ->where('provider', IdentityProvider::Steam)
            ->where('provider_id', $steamId)
            ->first();

        if ($owner !== null) {
            return $owner->user_id === $user->id
                ? SteamLinkResult::AlreadyLinked
                : SteamLinkResult::SteamOwnedByAnotherUser;
        }

        if ($user->hasSteam()) {
            return SteamLinkResult::UserAlreadyHasSteam;
        }

        try {
            $user->identities()->create([
                'provider' => IdentityProvider::Steam,
                'provider_id' => $steamId,
                'display_name' => $this->pilotName->for($steamUser),
                'avatar_url' => $this->pilotName->avatar($steamUser),
                'linked_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return SteamLinkResult::SteamOwnedByAnotherUser;
        }

        SteamLinked::dispatch($user->id, $steamId);

        return SteamLinkResult::Linked;
    }
}
