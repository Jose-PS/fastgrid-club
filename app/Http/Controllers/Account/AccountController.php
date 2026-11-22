<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\DeleteAccount;
use App\Actions\Auth\RecentConfirmation;
use App\Actions\Auth\SignInMethods;
use App\Http\Controllers\Controller;
use App\Http\Requests\DestroyAccountRequest;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\PublicRoutes;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

class AccountController extends Controller
{
    public function show(
        Request $request,
        Translations $translations,
        SignInMethods $signInMethods,
        RecentConfirmation $recentConfirmation,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $steam = $user->steamIdentity()->first();
        $google = $user->googleIdentity()->first();

        return Inertia::render('account/show', [
            'passkeys' => $user->passkeys()
                ->latest()
                ->get()
                ->map(fn (Passkey $passkey): array => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'createdAt' => $passkey->created_at?->toIso8601String(),
                    'lastUsedAt' => $passkey->last_used_at?->toIso8601String(),
                ]),
            'methods' => [
                'steam' => $steam === null ? null : [
                    'label' => $steam->display_name,
                    'linkedAt' => $steam->linked_at->toIso8601String(),
                    'canUnlink' => $signInMethods->canRemove($user, $steam),
                ],
                'google' => $google === null ? null : [
                    'label' => $this->googleLabel($google),
                    'linkedAt' => $google->linked_at->toIso8601String(),
                    'canUnlink' => $signInMethods->canRemove($user, $google),
                ],
                'email' => $user->email === null ? null : [
                    'address' => $user->email,
                    'verifiedAt' => $user->email_verified_at?->toIso8601String(),
                ],
            ],
            'profile' => [
                'name' => $user->name,
                'locale' => $user->locale ?? app()->getLocale(),
                'showInRankings' => $user->show_in_rankings,
            ],
            'locales' => config('mvp.locales'),
            'confirmedUntil' => $recentConfirmation->expiresAt($request->session())?->toIso8601String(),
            'pageTranslations' => $translations->forGroups(['account']),
        ]);
    }

    public function destroy(DestroyAccountRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleteAccount->handle($user, $request->boolean('hide_laps'));

        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::flash('toast', ['type' => 'info', 'message' => __('account.danger.deleted')]);

        return redirect()->route(PublicRoutes::name('home'));
    }

    private function googleLabel(UserIdentity $google): string
    {
        return $google->email ?? $google->display_name;
    }
}
