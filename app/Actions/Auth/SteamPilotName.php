<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SteamPilotName
{
    private const int MinLength = 3;

    private const int MaxLength = 32;

    public function for(SocialiteUser $steamUser): string
    {
        return $this->fromSteam($steamUser) ?? $this->automatic($steamUser);
    }

    public function fromSteam(SocialiteUser $steamUser): ?string
    {
        $nickname = $steamUser->getNickname();

        if (! is_string($nickname)) {
            return null;
        }

        $name = Str::limit(trim($nickname), self::MaxLength, '');

        return mb_strlen($name) >= self::MinLength ? $name : null;
    }

    public function automatic(SocialiteUser $steamUser): string
    {
        return 'Piloto '.substr((string) $steamUser->getId(), -4);
    }

    public function avatar(SocialiteUser $steamUser): ?string
    {
        $avatar = $steamUser->getAvatar();

        return is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null;
    }
}
