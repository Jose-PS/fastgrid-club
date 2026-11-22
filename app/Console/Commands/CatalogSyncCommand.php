<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalog\RunCatalogSync;
use App\Integration\Server\ServerDriverException;
use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:sync {server? : Slug of the server to sync from}')]
#[Description('Sync cars and circuits from the game server catalog')]
class CatalogSyncCommand extends Command
{
    public function handle(RunCatalogSync $runCatalogSync): int
    {
        $slug = $this->argument('server');

        $servers = Server::query()
            ->when($slug !== null, fn ($query) => $query->where('slug', $slug), fn ($query) => $query->where('is_active', true))
            ->get();

        if ($slug !== null && $servers->isEmpty()) {
            $this->error("Server {$slug} not found.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($servers as $server) {
            try {
                $sync = $runCatalogSync->handle($server);
            } catch (ServerDriverException $exception) {
                $this->error("{$server->slug}: {$exception->getMessage()}");
                $failed = true;

                continue;
            }

            $summary = $sync->summary;
            $this->info(sprintf(
                '%s: cars %d created, %d updated, %d deactivated; tracks %d created, %d updated, %d deactivated; %d unpaired race tracks.',
                $server->slug,
                $summary['cars']['created'],
                $summary['cars']['updated'],
                $summary['cars']['deactivated'],
                $summary['tracks']['created'],
                $summary['tracks']['updated'],
                $summary['tracks']['deactivated'],
                count($summary['unpaired_race_tracks']),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
