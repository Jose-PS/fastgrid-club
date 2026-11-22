<?php

declare(strict_types=1);

namespace App\Integration\Social;

use App\Enums\SocialChannelName;
use App\Enums\SocialPostType;
use App\Models\SocialAccount;
use App\Models\SocialPost;

class DiscordChannel implements SocialChannel
{
    public function name(): string
    {
        return 'discord';
    }

    public function isConfigured(SocialPost $post): bool
    {
        return $this->webhook($post) !== null;
    }

    public function publish(SocialPost $post, string $image): array
    {
        $webhook = $this->webhook($post);

        if ($webhook === null) {
            throw new SocialChannelException('discord: not configured');
        }

        $roleId = $this->roleId($post);
        $content = $roleId === null ? $post->text : "<@&{$roleId}>\n{$post->text}";

        $payload = [
            'content' => $content,
            'allowed_mentions' => $roleId === null ? ['parse' => []] : ['parse' => [], 'roles' => [$roleId]],
        ];

        $response = SocialHttp::send(
            fn () => SocialHttp::pending()
                ->withQueryParameters(['wait' => 'true'])
                ->attach('files[0]', $image, 'fastgrid.png')
                ->post($webhook, ['payload_json' => json_encode($payload, JSON_THROW_ON_ERROR)]),
            'discord',
        );

        $id = $response->json('id');

        return is_string($id) || is_int($id) ? ['id' => (string) $id] : [];
    }

    private function webhook(SocialPost $post): ?string
    {
        $key = $post->type === SocialPostType::WeekAnnouncement ? 'webhook_announcements' : 'webhook_results';

        return SocialAccount::enabledFor(SocialChannelName::Discord)?->credential($key);
    }

    private function roleId(SocialPost $post): ?string
    {
        if ($post->type !== SocialPostType::WeekAnnouncement) {
            return null;
        }

        $value = SocialAccount::enabledFor(SocialChannelName::Discord)?->credential('announcements_role_id');

        return $value !== null && preg_match('/^\d+$/', $value) === 1 ? $value : null;
    }
}
