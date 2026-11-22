<?php

declare(strict_types=1);

namespace App\Integration\Server;

use App\Enums\TrackMode;
use App\Integration\Server\Data\Catalog;
use App\Integration\Server\Data\CatalogCar;
use App\Integration\Server\Data\CatalogTrack;

final class ZinoMetadata
{
    public static function fromFile(string $path): Catalog
    {
        return self::toCatalog(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function toCatalog(array $data): Catalog
    {
        $cars = array_map(
            fn (array $car): CatalogCar => new CatalogCar(
                (string) $car['internal_name'],
                (string) $car['display_name'],
                (string) ($car['type'] ?? 'road'),
                (string) ($car['era'] ?? 'modern'),
                (string) ($car['engine'] ?? 'ice'),
                array_values(array_map('strval', (array) ($car['classes'] ?? []))),
                (bool) ($car['is_mod'] ?? false),
                isset($car['pi']) && is_numeric($car['pi']) ? (float) $car['pi'] : null,
                isset($car['runtime_name']) && $car['runtime_name'] !== '' ? (string) $car['runtime_name'] : null,
            ),
            array_values(array_filter((array) ($data['cars'] ?? []), 'is_array')),
        );

        $tracks = [
            ...self::tracks(TrackMode::Practice, (array) ($data['tracks']['practice'] ?? [])),
            ...self::tracks(TrackMode::RaceWeekend, (array) ($data['tracks']['race_weekend'] ?? [])),
        ];

        return new Catalog($cars, $tracks);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return list<CatalogTrack>
     */
    private static function tracks(TrackMode $mode, array $data): array
    {
        return array_map(
            fn (array $track): CatalogTrack => new CatalogTrack(
                $mode,
                (string) $track['token'],
                "{$track['track']} {$track['layout']}",
                (string) $track['track'],
                (string) $track['layout'],
                (string) $track['event_name'],
                (int) $track['length_m'],
                (int) $track['max_pit_slot'],
            ),
            array_values(array_filter($data, 'is_array')),
        );
    }
}
