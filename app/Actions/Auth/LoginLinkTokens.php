<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\LoginLinkPurpose;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginLinkTokens
{
    public function ttlMinutes(): int
    {
        return (int) config('mvp.login_link.ttl_minutes');
    }

    public function issue(string $email, LoginLinkPurpose $purpose, ?User $user, ?string $ip): string
    {
        $token = Str::random(64);

        DB::transaction(function () use ($email, $purpose, $user, $ip, $token): void {
            $previous = $purpose === LoginLinkPurpose::Login
                ? LoginLink::query()->where('email', $email)
                : LoginLink::query()->where('user_id', $user?->id);

            $previous
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            LoginLink::query()->create([
                'user_id' => $user?->id,
                'purpose' => $purpose,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'ip' => $ip,
            ]);
        });

        return $token;
    }

    /**
     * @param  array<int, LoginLinkPurpose>  $purposes
     */
    public function findUsable(string $token, array $purposes, ?int $userId = null): ?LoginLink
    {
        return $this->usable($token, $purposes, $userId)->first();
    }

    /**
     * @param  array<int, LoginLinkPurpose>  $purposes
     */
    public function claim(string $token, array $purposes, ?int $userId = null): ?LoginLink
    {
        $link = $this->usable($token, $purposes, $userId)->lockForUpdate()->first();

        if ($link === null) {
            return null;
        }

        $link->forceFill(['used_at' => now()])->save();

        return $link;
    }

    /**
     * @param  array<int, LoginLinkPurpose>  $purposes
     * @return Builder<LoginLink>
     */
    private function usable(string $token, array $purposes, ?int $userId): Builder
    {
        return LoginLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereIn('purpose', $purposes)
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }
}
