<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialChannelName: string
{
    case Discord = 'discord';
    case Bluesky = 'bluesky';
    case Instagram = 'instagram';

    /**
     * @return list<string>
     */
    public function secretFields(): array
    {
        return match ($this) {
            self::Discord => ['webhook_announcements', 'webhook_results'],
            self::Bluesky => ['app_password'],
            self::Instagram => ['access_token'],
        };
    }

    /**
     * @return list<string>
     */
    public function plainFields(): array
    {
        return match ($this) {
            self::Discord => ['announcements_role_id'],
            self::Bluesky => ['identifier'],
            self::Instagram => [],
        };
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return [...$this->plainFields(), ...$this->secretFields()];
    }

    /**
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::Discord => [],
            self::Bluesky => ['identifier', 'app_password'],
            self::Instagram => ['access_token'],
        };
    }
}
