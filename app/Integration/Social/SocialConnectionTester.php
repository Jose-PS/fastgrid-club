<?php

declare(strict_types=1);

namespace App\Integration\Social;

use App\Enums\SocialChannelName;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

class SocialConnectionTester
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function test(SocialChannelName $channel): array
    {
        $account = SocialAccount::forChannel($channel);

        if ($account === null || ! $this->canTest($account, $channel)) {
            return ['ok' => false, 'message' => __('admin.social.accounts.testNotConfigured')];
        }

        try {
            $failure = match ($channel) {
                SocialChannelName::Discord => $this->discord($account),
                SocialChannelName::Bluesky => $this->bluesky($account),
                SocialChannelName::Instagram => $this->instagram($account),
            };
        } catch (ConnectionException) {
            $failure = $this->text('admin.social.accounts.testConnection');
        } catch (Throwable) {
            $failure = $this->text('admin.social.accounts.testUnexpected');
        }

        return $failure === null
            ? ['ok' => true, 'message' => __('admin.social.accounts.testOk')]
            : ['ok' => false, 'message' => __('admin.social.accounts.testFailed', ['detail' => $failure])];
    }

    private function text(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }

    private function canTest(SocialAccount $account, SocialChannelName $channel): bool
    {
        if ($channel === SocialChannelName::Discord) {
            return $account->credential('webhook_announcements') !== null || $account->credential('webhook_results') !== null;
        }

        return $account->hasCredentials($channel);
    }

    private function discord(SocialAccount $account): ?string
    {
        foreach (['webhook_announcements', 'webhook_results'] as $field) {
            $url = $account->credential($field);

            if ($url === null) {
                continue;
            }

            $response = SocialHttp::pending()->get($url);

            if ($response->failed()) {
                return "{$field}: ".$this->status($response);
            }
        }

        return null;
    }

    private function bluesky(SocialAccount $account): ?string
    {
        $service = rtrim(config()->string('services.bluesky.service'), '/');

        $response = SocialHttp::pending()->asJson()->post("{$service}/xrpc/com.atproto.server.createSession", [
            'identifier' => ltrim((string) $account->credential('identifier'), '@'),
            'password' => (string) $account->credential('app_password'),
        ]);

        return $response->failed() ? $this->status($response) : null;
    }

    private function instagram(SocialAccount $account): ?string
    {
        $base = rtrim(config()->string('services.instagram.base_url'), '/').'/'.config()->string('services.instagram.api_version');

        $response = SocialHttp::pending()->get("{$base}/me", [
            'fields' => 'username',
            'access_token' => (string) $account->credential('access_token'),
        ]);

        return $response->failed() ? $this->status($response) : null;
    }

    private function status(Response $response): string
    {
        return 'HTTP '.$response->status();
    }
}
