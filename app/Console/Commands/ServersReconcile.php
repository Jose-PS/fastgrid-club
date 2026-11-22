<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\ReconcileServer;
use App\Enums\ReconcileResult;
use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servers:reconcile {server? : Slug of the server to reconcile}')]
#[Description('Apply the planned block configuration to every active server when it differs from the last applied one')]
class ServersReconcile extends Command
{
    public function handle(ReconcileServer $reconcile): int
    {
        $slug = $this->argument('server');

        $servers = Server::query()
            ->where('is_active', true)
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->where('slug', $slug))
            ->get();

        foreach ($servers as $server) {
            $result = $reconcile->handle($server);

            $this->line("{$server->slug}: {$result->value}");

            if ($result === ReconcileResult::Failed) {
                $this->warn("{$server->slug}: the configuration apply failed, it will be retried.");
            }
        }

        return self::SUCCESS;
    }
}
