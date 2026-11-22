<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LinkSteamIdentity;
use App\Actions\Auth\LoginRedirect;
use App\Actions\Auth\RecentConfirmation;
use App\Actions\Auth\SignInWithSteam;
use App\Actions\Proposals\VoteIntent;
use App\Auth\SteamProvider;
use App\Enums\IdentityProvider;
use App\Enums\SteamLinkResult;
use App\Http\Controllers\Controller;
use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use LogicException;
use SocialiteProviders\Steam\OpenIDValidationException;
use Throwable;

class SteamController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        return $this->driver($request)->redirect();
    }

    public function callback(
        Request $request,
        SignInWithSteam $signInWithSteam,
        LinkSteamIdentity $linkSteamIdentity,
        LoginRedirect $loginRedirect,
        RecentConfirmation $recentConfirmation,
        VoteIntent $voteIntent,
    ): RedirectResponse {
        $currentUser = $request->user();

        try {
            $steamUser = $this->driver($request)->user();
        } catch (Throwable $exception) {
            Log::warning('steam.callback.rejected', [
                'reason' => $exception::class,
                'detail' => $exception instanceof OpenIDValidationException ? $exception->getMessage() : null,
                'steam_ref' => $this->reference($request),
            ]);

            Inertia::flash('steamError', __('login.steamError'));

            return redirect()->route($currentUser === null ? 'login' : 'account.show');
        }

        if ($currentUser === null) {
            return $loginRedirect->completeLogin($signInWithSteam->handle($steamUser));
        }

        $ownsIdentity = $currentUser->identities()
            ->where('provider', IdentityProvider::Steam)
            ->where('provider_id', (string) $steamUser->getId())
            ->exists();

        if (! $ownsIdentity && ! $recentConfirmation->isFresh($request->session())) {
            return redirect()->route('account.confirm.show');
        }

        $result = $linkSteamIdentity->handle($currentUser, $steamUser);

        if ($result === SteamLinkResult::AlreadyLinked) {
            $request->session()->regenerate();
            $recentConfirmation->mark($request->session());
            Inertia::flash('toast', ['type' => 'success', 'message' => __('account.confirm.done')]);

            return redirect()->route('account.show');
        }

        if ($result !== SteamLinkResult::Linked) {
            Inertia::flash('steamError', __($result === SteamLinkResult::SteamOwnedByAnotherUser ? 'login.steamTaken' : 'login.steamAlready'));

            return redirect()->route('account.show');
        }

        $request->session()->regenerate();

        if ($voteIntent->apply($currentUser)?->counted() === true) {
            return redirect()->route(PublicRoutes::name('proposals'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.methods.linked.steam')]);

        return redirect()->route('account.show');
    }

    private function driver(Request $request): SteamProvider
    {
        Socialite::forgetDrivers();

        $driver = Socialite::driver('steam');

        if (! $driver instanceof SteamProvider) {
            throw new LogicException('The steam driver is not registered.');
        }

        $driver->setRequest($request);

        return $driver;
    }

    private function reference(Request $request): ?string
    {
        $input = $request->all();
        $claimedId = $input['openid.claimed_id'] ?? $input['openid_claimed_id'] ?? null;

        return is_string($claimedId) ? substr(hash('sha256', $claimedId), 0, 12) : null;
    }
}
