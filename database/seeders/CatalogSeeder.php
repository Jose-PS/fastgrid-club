<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Catalog\SyncCatalog;
use App\Integration\Server\Data\Catalog;
use App\Integration\Server\ZinoMetadata;
use App\Models\Server;
use Illuminate\Database\Seeder;
use RuntimeException;

class CatalogSeeder extends Seeder
{
    /**
     * @var array<string, string>|null
     */
    private ?array $countries = null;

    /**
     * @param  array<string, string>  $countries
     */
    public function withCountries(array $countries): static
    {
        $this->countries = $countries;

        return $this;
    }

    public function run(): void
    {
        $catalog = ZinoMetadata::fromFile(database_path('data/zino/metadata.json'));
        $countries = $this->countries ??= SyncCatalog::countries();

        $this->ensureCountries($catalog, $countries);

        app(SyncCatalog::class)->handle($this->server(), $catalog, $countries);
    }

    /**
     * @param  array<string, string>  $countries
     */
    private function ensureCountries(Catalog $catalog, array $countries): void
    {
        foreach ($catalog->tracks as $track) {
            if (! isset($countries[$track->track])) {
                throw new RuntimeException("Falta el pais de {$track->track} en database/data/track-countries.json");
            }
        }
    }

    private function server(): Server
    {
        $server = Server::query()->where('slug', 'main')->first();

        if ($server === null) {
            $this->call(ServerSeeder::class);
            $server = Server::query()->where('slug', 'main')->firstOrFail();
        }

        return $server;
    }
}
