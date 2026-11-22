<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\ConsumeAccountLink;
use App\Actions\Auth\RecentConfirmation;
use App\Enums\AccountLinkResult;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountLinkController extends Controller
{
    public function show(Request $request, string $token, ConsumeAccountLink $consumeAccountLink, Translations $translations): Response
    {
        /** @var User $user */
        $user = $request->user();
        $purpose = $consumeAccountLink->purposeFor($user, $token);

        return Inertia::render('account/link', [
            'purpose' => $purpose?->value,
            'consumeUrl' => $purpose === null ? null : route('account.link.consume', $token),
            'pageTranslations' => $translations->forGroups(['account']),
        ]);
    }

    public function consume(
        Request $request,
        string $token,
        ConsumeAccountLink $consumeAccountLink,
        RecentConfirmation $recentConfirmation,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $result = $consumeAccountLink->handle($user, $token);

        if ($result === AccountLinkResult::Invalid) {
            return redirect()->route('account.link.show', $token);
        }

        if ($result === AccountLinkResult::EmailTaken) {
            Inertia::flash('accountError', __('account.email.taken'));

            return redirect()->route('account.show');
        }

        $request->session()->regenerate();

        if ($result === AccountLinkResult::Confirmed) {
            $recentConfirmation->mark($request->session());
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __($result === AccountLinkResult::Confirmed ? 'account.confirm.done' : 'account.email.changed'),
        ]);

        return redirect()->route('account.show');
    }
}
