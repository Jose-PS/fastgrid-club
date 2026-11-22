<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Auth\RecentConfirmation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Webauthn\Exception\WebauthnException;

class PasskeyConfirmationController extends Controller
{
    public function store(
        PasskeyVerificationRequest $request,
        VerifyPasskey $verifyPasskey,
        RecentConfirmation $recentConfirmation,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $verifyPasskey($request->credential(), $request->verificationOptions(), $user);
        } catch (ValidationException|WebauthnException $exception) {
            Log::warning('passkey.confirm.rejected', ['reason' => $exception::class]);

            throw ValidationException::withMessages(['credential' => __('account.confirm.passkeyError')]);
        }

        $recentConfirmation->mark($request->session());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.confirm.done')]);

        return response()->json(['redirect' => route('account.show')]);
    }
}
