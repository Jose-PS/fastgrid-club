<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAudit;
use App\Models\Server;
use App\Models\User;

class SetPlanningPaused
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    public function handle(User $admin, Server $server, bool $paused): void
    {
        $before = $server->planning_paused;

        if ($before === $paused) {
            return;
        }

        $server->update(['planning_paused' => $paused]);

        $this->recordAudit->handle(
            $admin,
            $paused ? 'server.planning_paused' : 'server.planning_resumed',
            $server,
            ['planning_paused' => $before],
            ['planning_paused' => $paused],
        );
    }
}
