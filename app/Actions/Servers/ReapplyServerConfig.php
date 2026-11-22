<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAudit;
use App\Enums\ReconcileResult;
use App\Models\Server;
use App\Models\User;
use App\Support\AdminServerStatus;

class ReapplyServerConfig
{
    public function __construct(
        private readonly ReconcileServer $reconcile,
        private readonly RecordAudit $recordAudit,
        private readonly AdminServerStatus $status,
    ) {}

    public function handle(User $admin, Server $server): ReconcileResult
    {
        $players = $this->status->live($server)['players'];

        $result = $this->reconcile->handle($server, null, true);

        $this->recordAudit->handle(
            $admin,
            'server.reapplied',
            $server,
            ['connected_players' => $players],
            ['result' => $result->value],
        );

        return $result;
    }
}
