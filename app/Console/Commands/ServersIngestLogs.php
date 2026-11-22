<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\IngestServerLogs;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('servers:ingest-logs {server? : Slug of the server to ingest} {--backfill : Reprocess an older part of the log without touching the cursor} {--since= : UTC date and time to reprocess from (required with --backfill)}')]
#[Description('Read the log of every active server and store its laps, sessions and drivers')]
class ServersIngestLogs extends Command
{
    public function handle(IngestServerLogs $ingest): int
    {
        $slug = $this->argument('server');

        $servers = Server::query()
            ->where('is_active', true)
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->where('slug', $slug))
            ->get();

        if ($this->option('backfill')) {
            return $this->backfill($ingest, $servers);
        }

        $ingested = 0;

        foreach ($servers as $server) {
            if ($ingest->handle($server)) {
                $ingested++;
            }
        }

        $this->info("Servers ingested: {$ingested}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Server>  $servers
     */
    private function backfill(IngestServerLogs $ingest, Collection $servers): int
    {
        $sinceOption = $this->option('since');

        if (! is_string($sinceOption) || $sinceOption === '') {
            $this->error('The --since option is required with --backfill.');

            return self::FAILURE;
        }

        try {
            $since = CarbonImmutable::parse($sinceOption, 'UTC');
        } catch (Throwable) {
            $this->error("Invalid --since value: {$sinceOption}.");

            return self::FAILURE;
        }

        $status = self::SUCCESS;

        foreach ($servers as $server) {
            try {
                $result = $ingest->backfill($server, $since);
            } catch (Throwable $exception) {
                $this->error("{$server->slug}: {$exception->getMessage()}");
                $status = self::FAILURE;

                continue;
            }

            $this->info("{$server->slug}: processed {$result['lines']} lines, {$result['laps']} new laps.");
        }

        return $status;
    }
}
