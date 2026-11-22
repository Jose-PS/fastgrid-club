<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\LogoutOtherSessions;
use App\Actions\Auth\SessionVersion;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SessionController extends Controller
{
    public function destroy(Request $request, LogoutOtherSessions $logoutOtherSessions, SessionVersion $sessionVersion): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $logoutOtherSessions->handle($user);

        Auth::guard('web')->login($user, remember: true);
        $sessionVersion->remember($request->session(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.danger.sessionsClosed')]);

        return redirect()->route('account.show');
    }
}
