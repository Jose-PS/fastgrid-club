<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginRedirect;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkeys;
use Webauthn\Exception\WebauthnException;

class PasskeyLoginController extends Controller
{
    public function store(
        PasskeyVerificationRequest $request,
        VerifyPasskey $verifyPasskey,
        LoginRedirect $loginRedirect,
    ): JsonResponse {
        try {
            $passkey = $verifyPasskey($request->credential(), $request->verificationOptions());
        } catch (ValidationException|WebauthnException $exception) {
            Log::warning('passkey.login.rejected', ['reason' => $exception::class]);

            throw ValidationException::withMessages(['credential' => __('login.passkeyError')]);
        }

        $user = $passkey->user;

        if (! $user instanceof User || ! Passkeys::allowsLogin($request, $passkey)) {
            throw ValidationException::withMessages(['credential' => __('login.passkeyError')]);
        }

        return response()->json([
            'redirect' => $loginRedirect->completeLogin($user)->getTargetUrl(),
        ]);
    }
}
