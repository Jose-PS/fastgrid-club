<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class LogEvent
{
    public function __construct(public int $id, public string $line) {}
}
