<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class ServerStatusReport
{
    public function __construct(
        public bool $running,
        public string $state,
        public ?int $lastExitCode,
    ) {}
}
