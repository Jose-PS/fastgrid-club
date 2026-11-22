<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class LogTail
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(public array $lines) {}
}
