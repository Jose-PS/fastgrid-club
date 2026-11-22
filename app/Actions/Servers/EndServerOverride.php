<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAudit;
use App\Enums\ReconcileResult;
use App\Models\Server;
use App\Models\ServerOverride;
use App\Models\User;
use Carbon\CarbonImmutable;

class EndServerOverride
{
    public function __construct(
        private readonly ReconcileServer $reconcile,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function handle(User $admin, Server $server): ?ReconcileResult
    {
        $now = CarbonImmutable::now();

        $active = ServerOverride::query()
            ->where('server_id', $server->id)
            ->active($now)
            ->get();

        if ($active->isEmpty()) {
            return null;
        }

        foreach ($active as $override) {
            $override->forceFill(['ended_at' => $now])->save();

            $this->recordAudit->handle(
                $admin,
                'server.override_ended',
                $server,
                ['override_id' => $override->id, 'until' => $override->until?->toIso8601String()],
                ['ended_at' => $now->toIso8601String()],
            );
        }

        if ($server->planning_paused) {
            return ReconcileResult::Paused;
        }

        return $this->reconcile->handle($server, null, true);
    }
}
