<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class LiveSnapshot
{
    /**
     * @param  list<LiveDriver>  $drivers
     */
    public function __construct(
        public bool $running,
        public int $players,
        public array $drivers,
    ) {}
}
