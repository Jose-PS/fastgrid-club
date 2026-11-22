<?php

declare(strict_types=1);

namespace App\Integration\Server\Data;

final readonly class Catalog
{
    /**
     * @param  list<CatalogCar>  $cars
     * @param  list<CatalogTrack>  $tracks
     */
    public function __construct(
        public array $cars,
        public array $tracks,
    ) {}
}
