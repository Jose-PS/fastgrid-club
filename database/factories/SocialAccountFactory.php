<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SocialChannelName;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => SocialChannelName::Discord->value,
            'enabled' => true,
            'credentials' => [],
            'expires_at' => null,
            'refreshed_at' => null,
        ];
    }

    public function discord(): static
    {
        return $this->state(fn (): array => [
            'channel' => SocialChannelName::Discord->value,
            'credentials' => [
                'webhook_announcements' => 'https://discord.com/api/webhooks/1/secret-token',
                'webhook_results' => 'https://discord.com/api/webhooks/2/results-token',
                'announcements_role_id' => '',
            ],
        ]);
    }

    public function bluesky(): static
    {
        return $this->state(fn (): array => [
            'channel' => SocialChannelName::Bluesky->value,
            'credentials' => ['identifier' => 'fastgrid.bsky.social', 'app_password' => 'app-secret'],
        ]);
    }

    public function instagram(): static
    {
        return $this->state(fn (): array => [
            'channel' => SocialChannelName::Instagram->value,
            'credentials' => ['access_token' => 'ig-secret', 'ig_user_id' => '17841400000000000'],
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
