<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Proposals\VoteIntent;
use App\Models\User;
use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class LoginRedirect
{
    private const string SessionKey = 'url.intended';

    public function __construct(
        private readonly RecentConfirmation $recentConfirmation,
        private readonly SessionVersion $sessionVersion,
        private readonly VoteIntent $voteIntent,
    ) {}

    public function remember(?string $url): void
    {
        if ($url !== null && $this->isInternal($url)) {
            session()->put(self::SessionKey, $url);
        }
    }

    public function completeLogin(User $user): RedirectResponse
    {
        Auth::guard('web')->login($user, remember: true);
        session()->regenerate();
        $this->recentConfirmation->mark(session()->driver());
        $this->sessionVersion->remember(session()->driver(), $user);
        $this->voteIntent->apply($user);

        return $this->redirect();
    }

    public function redirect(): RedirectResponse
    {
        $url = session()->pull(self::SessionKey);

        return redirect()->to(is_string($url) && $this->isInternal($url) ? $url : route(PublicRoutes::name('home')));
    }

    public function isInternal(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $target = parse_url($url);
        $app = parse_url((string) config('app.url'));

        if ($target === false || $app === false || ! isset($target['scheme'], $target['host'], $app['scheme'], $app['host'])) {
            return false;
        }

        return strtolower($target['scheme']) === strtolower($app['scheme'])
            && strtolower($target['host']) === strtolower($app['host'])
            && ($target['port'] ?? null) === ($app['port'] ?? null)
            && ! isset($target['user'])
            && ! isset($target['pass']);
    }
}
