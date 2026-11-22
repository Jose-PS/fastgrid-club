<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserIdentity;
use Laravel\Passkeys\Passkey;

class SignInMethods
{
    public const string Email = 'email';

    public function count(User $user): int
    {
        return $user->identities()->count()
            + $user->passkeys()->count()
            + ($this->hasVerifiedEmail($user) ? 1 : 0);
    }

    /**
     * @param  UserIdentity|Passkey|self::Email  $method
     */
    public function canRemove(User $user, UserIdentity|Passkey|string $method): bool
    {
        $remaining = $this->count($user) - ($this->isCountedFor($user, $method) ? 1 : 0);

        return $remaining >= 1;
    }

    private function isCountedFor(User $user, UserIdentity|Passkey|string $method): bool
    {
        return match (true) {
            $method instanceof UserIdentity => $method->user_id === $user->id,
            $method instanceof Passkey => (int) $method->user_id === $user->id,
            $method === self::Email => $this->hasVerifiedEmail($user),
            default => false,
        };
    }

    private function hasVerifiedEmail(User $user): bool
    {
        return $user->email !== null && $user->email_verified_at !== null;
    }
}
