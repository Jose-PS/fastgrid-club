<?php

declare(strict_types=1);

use App\Http\Controllers\Account\PasskeyConfirmationController;
use App\Http\Controllers\Account\PasskeyController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Middleware\ExpirePasskeyChallenge;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController as PackagePasskeyConfirmationController;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController as PackagePasskeyLoginController;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;

Route::middleware(['guest:web', 'throttle:passkeys', ExpirePasskeyChallenge::class])->group(function () {
    Route::get('passkeys/login/options', [PackagePasskeyLoginController::class, 'index'])->name('passkey.login-options');
    Route::post('passkeys/login', [PasskeyLoginController::class, 'store'])->name('passkey.login');
});

Route::middleware(['auth:web', 'throttle:passkeys', ExpirePasskeyChallenge::class])->group(function () {
    Route::get('passkeys/confirm/options', [PackagePasskeyConfirmationController::class, 'index'])->name('passkey.confirm-options');
    Route::post('passkeys/confirm', [PasskeyConfirmationController::class, 'store'])->name('passkey.confirm');
    Route::get('user/passkeys/options', [PasskeyRegistrationController::class, 'index'])->name('passkey.registration-options');
    Route::post('user/passkeys', [PasskeyController::class, 'store'])->name('passkey.store');
});

Route::middleware('auth:web')->group(function () {
    Route::patch('user/passkeys/{passkey}', [PasskeyController::class, 'update'])->name('passkey.update');
    Route::delete('user/passkeys/{passkey}', [PasskeyController::class, 'destroy'])
        ->middleware('recently-confirmed')
        ->name('passkey.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('account.show'),
        'manage' => route('account.show'),
    ]);
})->name('well-known.passkeys');
