<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SocialChannelName;
use App\Integration\Social\SocialChannelException;
use App\Integration\Social\SocialHttp;
use App\Mail\InstagramTokenRefreshFailedMail;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

#[Signature('social:refresh-instagram-token')]
#[Description('Renew the Instagram long-lived access token when it expires in less than 30 days')]
class SocialRefreshInstagramToken extends Command
{
    private const RENEW_BEFORE_DAYS = 30;

    public function handle(): int
    {
        $account = SocialAccount::forChannel(SocialChannelName::Instagram);
        $current = $account?->credential('access_token');

        if ($account === null || $current === null) {
            $this->info('Instagram token not configured.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $expiresAt = $account->expires_at;

        if ($expiresAt !== null && $expiresAt->greaterThan($now->addDays(self::RENEW_BEFORE_DAYS))) {
            $this->info('Instagram token does not need renewal yet.');

            return self::SUCCESS;
        }

        try {
            $response = SocialHttp::send(
                fn () => SocialHttp::pending()->get(rtrim(config()->string('services.instagram.base_url'), '/').'/refresh_access_token', [
                    'grant_type' => 'ig_refresh_token',
                    'access_token' => $current,
                ]),
                'instagram refresh',
            );

            $token = $response->json('access_token');
            $expiresIn = $response->json('expires_in');

            if (! is_string($token) || $token === '' || ! is_numeric($expiresIn)) {
                throw new SocialChannelException('instagram refresh: unexpected response');
            }

            $account->forceFill([
                'credentials' => [...$account->credentials, 'access_token' => $token],
                'expires_at' => $now->addSeconds((int) $expiresIn),
                'refreshed_at' => $now,
            ])->save();
        } catch (SocialChannelException $exception) {
            Log::error('Instagram token refresh failed', ['error' => $exception->getMessage()]);

            User::query()
                ->where('is_admin', true)
                ->whereNotNull('email')
                ->each(fn (User $admin) => Mail::to($admin)->send(new InstagramTokenRefreshFailedMail));

            $this->error('Instagram token refresh failed.');

            return self::FAILURE;
        }

        $this->info('Instagram token renewed.');

        return self::SUCCESS;
    }
}
