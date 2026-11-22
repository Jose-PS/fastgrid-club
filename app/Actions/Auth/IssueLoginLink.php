<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginLinkPurpose;
use App\Mail\LoginLinkMail;
use Illuminate\Support\Facades\Mail;

class IssueLoginLink
{
    public function __construct(private readonly LoginLinkTokens $tokens) {}

    public function handle(string $email, ?string $ip, string $locale): void
    {
        $token = $this->tokens->issue($email, LoginLinkPurpose::Login, null, $ip);

        Mail::to($email)
            ->locale($locale)
            ->send(new LoginLinkMail(route('login.link.show', $token), $this->tokens->ttlMinutes()));
    }
}
