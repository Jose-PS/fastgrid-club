<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class ServerSecrets
{
    public function __construct(
        #[\SensitiveParameter] public ?string $driverPassword = null,
        #[\SensitiveParameter] public ?string $adminPassword = null,
        #[\SensitiveParameter] public ?string $spectatorPassword = null,
    ) {}

    public function __debugInfo(): array
    {
        return [];
    }
}
