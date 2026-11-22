<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LinkGoogleIdentity;
use App\Actions\Auth\LoginRedirect;
use App\Actions\Auth\RecentConfirmation;
use App\Actions\Auth\SignInWithGoogle;
use App\Actions\Proposals\VoteIntent;
use App\Enums\GoogleLinkResult;
use App\Enums\GoogleSignInRejection;
use App\Enums\IdentityProvider;
use App\Http\Controllers\Controller;
use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use LogicException;
use Throwable;

class GoogleController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        return $this->driver($request)
            ->scopes(['openid', 'email', 'profile'])
            ->enablePKCE()
            ->redirect();
    }

    public function callback(
        Request $request,
        SignInWithGoogle $signInWithGoogle,
        LinkGoogleIdentity $linkGoogleIdentity,
        LoginRedirect $loginRedirect,
        RecentConfirmation $recentConfirmation,
        VoteIntent $voteIntent,
    ): RedirectResponse {
        $currentUser = $request->user();

        try {
            $googleUser = $this->driver($request)->enablePKCE()->user();
        } catch (Throwable $exception) {
            Log::warning('google.callback.rejected', ['reason' => $exception::class]);

            return $this->reject($currentUser !== null, 'login.googleError');
        }

        if ($currentUser === null) {
            $result = $signInWithGoogle->handle($googleUser);

            if ($result instanceof GoogleSignInRejection) {
                Log::info('google.callback.rejected', ['reason' => $result->name]);

                return $this->reject(false, $result === GoogleSignInRejection::EmailInUse ? 'login.googleEmailInUse' : 'login.googleError');
            }

            return $loginRedirect->completeLogin($result);
        }

        $ownsIdentity = $currentUser->identities()
            ->where('provider', IdentityProvider::Google)
            ->where('provider_id', (string) $googleUser->getId())
            ->exists();

        if (! $ownsIdentity && ! $recentConfirmation->isFresh($request->session())) {
            return redirect()->route('account.confirm.show');
        }

        $result = $linkGoogleIdentity->handle($currentUser, $googleUser);

        if ($result === GoogleLinkResult::AlreadyLinked) {
            $request->session()->regenerate();
            $recentConfirmation->mark($request->session());
            Inertia::flash('toast', ['type' => 'success', 'message' => __('account.confirm.done')]);

            return redirect()->route('account.show');
        }

        if ($result !== GoogleLinkResult::Linked) {
            Log::info('google.callback.rejected', ['reason' => $result->name]);

            return $this->reject(true, $result === GoogleLinkResult::GoogleOwnedByAnotherUser ? 'login.googleTaken' : 'login.googleAlready');
        }

        $request->session()->regenerate();

        if ($voteIntent->apply($currentUser)?->counted() === true) {
            return redirect()->route(PublicRoutes::name('proposals'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.methods.linked.google')]);

        return redirect()->route('account.show');
    }

    private function reject(bool $authenticated, string $messageKey): RedirectResponse
    {
        Inertia::flash('googleError', __($messageKey));

        return redirect()->route($authenticated ? 'account.show' : 'login');
    }

    private function driver(Request $request): GoogleProvider
    {
        Socialite::forgetDrivers();

        $driver = Socialite::driver('google');

        if (! $driver instanceof GoogleProvider) {
            throw new LogicException('The google driver is not registered.');
        }

        $driver->setRequest($request);

        return $driver;
    }
}
