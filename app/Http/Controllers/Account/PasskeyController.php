<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Auth\RemovePasskey;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePasskeyRequest;
use App\Http\Requests\UpdatePasskeyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Webauthn\Exception\WebauthnException;

class PasskeyController extends Controller
{
    public function store(StorePasskeyRequest $request, StorePasskey $storePasskey): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $passkey = $storePasskey(
                $user,
                $request->string('name')->toString(),
                $request->credential(),
                $request->registrationOptions(),
            );
        } catch (ValidationException|WebauthnException $exception) {
            Log::warning('passkey.registration.rejected', ['reason' => $exception::class]);

            throw ValidationException::withMessages(['credential' => __('account.passkeys.addError')]);
        }

        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.passkeys.added')]);

        return response()->json([
            'status' => 'passkey-registered',
            'id' => (string) $passkey->id,
            'name' => $passkey->name,
        ]);
    }

    public function update(UpdatePasskeyRequest $request, Passkey $passkey): RedirectResponse
    {
        $passkey->forceFill(['name' => $request->validated('name')])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.passkeys.renamed')]);

        return back(fallback: route('account.show'));
    }

    public function destroy(Request $request, Passkey $passkey, RemovePasskey $removePasskey): RedirectResponse
    {
        Gate::authorize('delete', $passkey);

        /** @var User $user */
        $user = $request->user();

        if (! $removePasskey->handle($user, $passkey)) {
            throw ValidationException::withMessages(['passkey' => __('account.passkeys.lastMethod')]);
        }

        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.passkeys.deleted')]);

        return back(fallback: route('account.show'));
    }
}
