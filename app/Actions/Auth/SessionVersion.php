<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

class SessionVersion
{
    private const string SessionKey = 'auth.session_version';

    public function remember(Session $session, User $user): void
    {
        $session->put(self::SessionKey, $user->session_version);
    }

    public function isCurrent(Session $session, User $user): bool
    {
        $version = $session->get(self::SessionKey);

        if (! is_int($version)) {
            $this->remember($session, $user);

            return true;
        }

        return $version === $user->session_version;
    }
}
