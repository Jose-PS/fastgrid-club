<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Laravel\Passkeys\Passkey;

class PasskeyPolicy
{
    public function update(User $user, Passkey $passkey): bool
    {
        return $this->owns($user, $passkey);
    }

    public function delete(User $user, Passkey $passkey): bool
    {
        return $this->owns($user, $passkey);
    }

    private function owns(User $user, Passkey $passkey): bool
    {
        return (string) $passkey->user_id === (string) $user->id;
    }
}
