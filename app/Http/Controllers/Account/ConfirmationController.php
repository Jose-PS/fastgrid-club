<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\IssueAccountLink;
use App\Actions\Auth\RecentConfirmation;
use App\Enums\LoginLinkPurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmationController extends Controller
{
    public function show(Request $request, Translations $translations, RecentConfirmation $recentConfirmation): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('account/confirm', [
            'methods' => [
                'passkey' => $user->passkeys()->exists(),
                'steam' => $user->steamIdentity()->exists(),
                'google' => $user->googleIdentity()->exists(),
                'email' => $user->email !== null && $user->email_verified_at !== null,
            ],
            'minutes' => $recentConfirmation->minutes(),
            'pageTranslations' => $translations->forGroups(['account']),
        ]);
    }

    public function store(Request $request, IssueAccountLink $issueAccountLink): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email !== null && $user->email_verified_at !== null) {
            $issueAccountLink->handle($user, LoginLinkPurpose::Confirmation, $user->email, $request->ip(), app()->getLocale());
        }

        Inertia::flash('linkSent', __('account.confirm.linkSent'));

        return redirect()->route('account.confirm.show');
    }
}
