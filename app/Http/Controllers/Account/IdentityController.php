<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\UnlinkIdentity;
use App\Enums\IdentityProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class IdentityController extends Controller
{
    public function destroy(Request $request, IdentityProvider $provider, UnlinkIdentity $unlinkIdentity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $identity = $user->identities()->where('provider', $provider)->firstOrFail();

        if (! $unlinkIdentity->handle($user, $identity)) {
            throw ValidationException::withMessages(['identity' => __('account.methods.lastMethod')]);
        }

        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.methods.unlinked.'.$provider->value)]);

        return redirect()->route('account.show');
    }
}
