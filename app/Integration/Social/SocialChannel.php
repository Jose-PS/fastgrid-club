<?php

declare(strict_types=1);

namespace App\Integration\Social;

use App\Models\SocialPost;

interface SocialChannel
{
    public function name(): string;

    public function isConfigured(SocialPost $post): bool;

    /**
     * @return array<string, string>
     */
    public function publish(SocialPost $post, string $image): array;
}
