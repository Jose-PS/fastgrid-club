<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;

class RecentConfirmation
{
    private const string SessionKey = 'auth.password_confirmed_at';

    public function mark(Session $session): void
    {
        $session->put(self::SessionKey, Date::now()->getTimestamp());
    }

    public function isFresh(Session $session): bool
    {
        $expiresAt = $this->expiresAt($session);

        return $expiresAt !== null && $expiresAt->isFuture();
    }

    public function expiresAt(Session $session): ?CarbonImmutable
    {
        $confirmedAt = $session->get(self::SessionKey);

        if (! is_int($confirmedAt)) {
            return null;
        }

        $expiresAt = CarbonImmutable::createFromTimestamp($confirmedAt + $this->minutes() * 60);

        return $expiresAt->isFuture() ? $expiresAt : null;
    }

    public function minutes(): int
    {
        return (int) config('mvp.recent_confirmation.minutes');
    }
}
