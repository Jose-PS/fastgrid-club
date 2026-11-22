<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\PollServer;
use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servers:poll')]
#[Description('Poll every active server and store its snapshot')]
class ServersPoll extends Command
{
    public function handle(PollServer $pollServer): int
    {
        $polled = 0;

        Server::query()->where('is_active', true)->each(function (Server $server) use ($pollServer, &$polled): void {
            if ($pollServer->handle($server)) {
                $polled++;
            }
        });

        $this->info("Servers polled: {$polled}.");

        return self::SUCCESS;
    }
}
