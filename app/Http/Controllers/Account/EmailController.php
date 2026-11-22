<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\IssueAccountLink;
use App\Enums\LoginLinkPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmailRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class EmailController extends Controller
{
    public function update(UpdateEmailRequest $request, IssueAccountLink $issueAccountLink): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $email = $request->validated('email');

        $issueAccountLink->handle($user, LoginLinkPurpose::EmailChange, $email, $request->ip(), app()->getLocale());

        Inertia::flash('emailSent', __('account.email.sent', ['email' => $email]));

        return redirect()->route('account.show');
    }
}
