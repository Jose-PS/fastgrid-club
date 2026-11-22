<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\GoogleLinkResult;
use App\Enums\IdentityProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class LinkGoogleIdentity
{
    public function __construct(private readonly GooglePilotName $pilotName) {}

    public function handle(User $user, SocialiteUser $googleUser): GoogleLinkResult
    {
        $googleId = (string) $googleUser->getId();

        $owner = UserIdentity::query()
            ->where('provider', IdentityProvider::Google)
            ->where('provider_id', $googleId)
            ->first();

        if ($owner !== null) {
            return $owner->user_id === $user->id
                ? GoogleLinkResult::AlreadyLinked
                : GoogleLinkResult::GoogleOwnedByAnotherUser;
        }

        if ($user->identities()->where('provider', IdentityProvider::Google)->exists()) {
            return GoogleLinkResult::UserAlreadyHasGoogle;
        }

        try {
            $user->identities()->create([
                'provider' => IdentityProvider::Google,
                'provider_id' => $googleId,
                'display_name' => $this->pilotName->fromGoogle($googleUser) ?? $user->name,
                'email' => $this->pilotName->email($googleUser),
                'avatar_url' => $this->pilotName->avatar($googleUser),
                'linked_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return GoogleLinkResult::GoogleOwnedByAnotherUser;
        }

        return GoogleLinkResult::Linked;
    }
}
