<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class OperationResult
{
    public function __construct(
        public bool $ok,
        public string $message = '',
    ) {}
}
