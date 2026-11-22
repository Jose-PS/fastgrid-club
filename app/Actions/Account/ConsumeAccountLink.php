<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Auth\LoginLinkTokens;
use App\Enums\AccountLinkResult;
use App\Enums\LoginLinkPurpose;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ConsumeAccountLink
{
    private const array Purposes = [LoginLinkPurpose::Confirmation, LoginLinkPurpose::EmailChange];

    public function __construct(private readonly LoginLinkTokens $tokens) {}

    public function purposeFor(User $user, string $token): ?LoginLinkPurpose
    {
        return $this->tokens->findUsable($token, self::Purposes, $user->id)?->purpose;
    }

    public function handle(User $user, string $token): AccountLinkResult
    {
        try {
            return DB::transaction(function () use ($user, $token): AccountLinkResult {
                $link = $this->tokens->findUsable($token, self::Purposes, $user->id);

                if ($link === null) {
                    return AccountLinkResult::Invalid;
                }

                if ($link->purpose === LoginLinkPurpose::EmailChange && $this->emailTaken($user, $link->email)) {
                    return AccountLinkResult::EmailTaken;
                }

                if ($this->tokens->claim($token, self::Purposes, $user->id) === null) {
                    return AccountLinkResult::Invalid;
                }

                if ($link->purpose === LoginLinkPurpose::Confirmation) {
                    return AccountLinkResult::Confirmed;
                }

                $user->forceFill([
                    'email' => $link->email,
                    'email_verified_at' => now(),
                ])->save();

                return AccountLinkResult::EmailChanged;
            });
        } catch (UniqueConstraintViolationException) {
            return AccountLinkResult::EmailTaken;
        }
    }

    private function emailTaken(User $user, string $email): bool
    {
        return User::query()->where('email', $email)->whereKeyNot($user->id)->exists();
    }
}
