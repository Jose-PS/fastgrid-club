<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GooglePilotName
{
    private const int MinLength = 3;

    private const int MaxLength = 32;

    public function for(SocialiteUser $googleUser): string
    {
        return $this->fromGoogle($googleUser) ?? 'Piloto '.random_int(1000, 9999);
    }

    public function fromGoogle(SocialiteUser $googleUser): ?string
    {
        $name = $googleUser->getName();

        if (! is_string($name)) {
            return null;
        }

        $name = Str::limit(trim($name), self::MaxLength, '');

        return mb_strlen($name) >= self::MinLength ? $name : null;
    }

    public function email(SocialiteUser $googleUser): ?string
    {
        $email = $googleUser->getEmail();

        return is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
    }

    public function avatar(SocialiteUser $googleUser): ?string
    {
        $avatar = $googleUser->getAvatar();

        return is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null;
    }
}
