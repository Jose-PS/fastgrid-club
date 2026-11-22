<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Auth\LoginLinkTokens;
use App\Enums\LoginLinkPurpose;
use App\Mail\AccountLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class IssueAccountLink
{
    public function __construct(private readonly LoginLinkTokens $tokens) {}

    public function handle(User $user, LoginLinkPurpose $purpose, string $email, ?string $ip, string $locale): void
    {
        $token = $this->tokens->issue($email, $purpose, $user, $ip);

        Mail::to($email)
            ->locale($locale)
            ->send(new AccountLinkMail(route('account.link.show', $token), $this->tokens->ttlMinutes(), $purpose));
    }
}
