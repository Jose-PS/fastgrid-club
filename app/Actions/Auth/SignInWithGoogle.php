<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\GoogleSignInRejection;
use App\Enums\IdentityProvider;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User as OAuthTwoUser;

class SignInWithGoogle
{
    public function __construct(private readonly GooglePilotName $pilotName) {}

    public function handle(SocialiteUser $googleUser): User|GoogleSignInRejection
    {
        if (! $this->hasVerifiedEmail($googleUser)) {
            return GoogleSignInRejection::EmailNotVerified;
        }

        $googleId = (string) $googleUser->getId();

        $identity = $this->findIdentity($googleId);

        if ($identity !== null) {
            $this->refreshProfile($identity, $googleUser);

            return $identity->user;
        }

        $email = $this->normalizedEmail($googleUser);

        if ($this->emailTaken($email)) {
            return GoogleSignInRejection::EmailInUse;
        }

        try {
            return DB::transaction(function () use ($googleUser, $googleId, $email): User {
                $name = $this->pilotName->for($googleUser);

                $user = new User([
                    'name' => $name,
                    'email' => $email,
                    'locale' => app()->getLocale(),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $user->identities()->create([
                    'provider' => IdentityProvider::Google,
                    'provider_id' => $googleId,
                    'display_name' => $name,
                    'email' => $email,
                    'avatar_url' => $this->pilotName->avatar($googleUser),
                    'linked_at' => now(),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            $identity = $this->findIdentity($googleId);

            if ($identity !== null) {
                return $identity->user;
            }

            return GoogleSignInRejection::EmailInUse;
        }
    }

    private function emailTaken(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    private function hasVerifiedEmail(SocialiteUser $googleUser): bool
    {
        $raw = $googleUser instanceof OAuthTwoUser ? $googleUser->user : [];

        return ($raw['email_verified'] ?? false) === true && $this->normalizedEmail($googleUser) !== '';
    }

    private function normalizedEmail(SocialiteUser $googleUser): string
    {
        $email = $googleUser->getEmail();

        return is_string($email) ? mb_strtolower(trim($email)) : '';
    }

    private function refreshProfile(UserIdentity $identity, SocialiteUser $googleUser): void
    {
        $name = $this->pilotName->fromGoogle($googleUser);

        if ($name !== null) {
            $identity->display_name = $name;
        }

        $identity->email = $this->pilotName->email($googleUser) ?? $identity->email;
        $identity->avatar_url = $this->pilotName->avatar($googleUser);
        $identity->save();
    }

    private function findIdentity(string $googleId): ?UserIdentity
    {
        return UserIdentity::query()
            ->where('provider', IdentityProvider::Google)
            ->where('provider_id', $googleId)
            ->first();
    }
}
