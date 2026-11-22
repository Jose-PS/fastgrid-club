<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class CatalogCar
{
    /**
     * @param  list<string>  $classes
     */
    public function __construct(
        public string $carRef,
        public string $displayName,
        public string $type,
        public string $era,
        public string $engine,
        public array $classes,
        public bool $isMod,
        public ?float $pi = null,
        public ?string $runtimeName = null,
    ) {}
}
