<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class LogEvents
{
    /**
     * @param  list<LogEvent>  $events
     */
    public function __construct(public array $events, public ?int $firstId, public ?int $lastId) {}
}
