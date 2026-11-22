<?php

declare(strict_types=1);

namespace App\Enums;

enum DonationPlatform: string
{
    case KoFi = 'ko_fi';
    case Patreon = 'patreon';
    case Paypal = 'paypal';

    public function domain(): string
    {
        return config()->string("mvp.donations.domains.{$this->value}");
    }

    public function position(): int
    {
        return match ($this) {
            self::KoFi => 0,
            self::Patreon => 1,
            self::Paypal => 2,
        };
    }
}
