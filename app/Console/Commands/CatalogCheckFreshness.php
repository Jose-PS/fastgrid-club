<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalog\CheckCatalogFreshness;
use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:check-freshness')]
#[Description('Open or close the stale catalog alert for every active server')]
class CatalogCheckFreshness extends Command
{
    public function handle(CheckCatalogFreshness $check): int
    {
        foreach (Server::query()->where('is_active', true)->get() as $server) {
            $stale = $check->handle($server);

            $this->line("{$server->slug}: ".($stale ? 'stale' : 'fresh'));
        }

        return self::SUCCESS;
    }
}
