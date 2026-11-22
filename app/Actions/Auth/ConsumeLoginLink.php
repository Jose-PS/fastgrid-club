<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginLinkPurpose;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsumeLoginLink
{
    public function __construct(private readonly LoginLinkTokens $tokens) {}

    public function isUsable(string $token): bool
    {
        return $this->tokens->findUsable($token, [LoginLinkPurpose::Login]) !== null;
    }

    public function handle(string $token): ?User
    {
        return DB::transaction(function () use ($token): ?User {
            $link = $this->tokens->claim($token, [LoginLinkPurpose::Login]);

            return $link === null ? null : $this->userFor($link->email);
        });
    }

    private function userFor(string $email): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $name = Str::limit(Str::before($email, '@'), 32, '');

            if (mb_strlen($name) < 3) {
                $name = 'Piloto '.random_int(1000, 9999);
            }

            $user = new User([
                'name' => $name,
                'email' => $email,
                'locale' => app()->getLocale(),
            ]);
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->save();

        return $user;
    }
}
