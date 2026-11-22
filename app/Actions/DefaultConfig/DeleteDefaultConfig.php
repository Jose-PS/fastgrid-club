<?php

declare(strict_types=1);

namespace App\Actions\DefaultConfig;

use App\Actions\Audit\RecordAudit;
use App\Models\Server;
use App\Models\ServerDefaultConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteDefaultConfig
{
    public function __construct(private readonly RecordAudit $recordAudit, private readonly SaveDefaultConfig $save) {}

    public function handle(User $user, Server $server): void
    {
        DB::transaction(function () use ($user, $server): void {
            $config = ServerDefaultConfig::query()->where('server_id', $server->id)->lockForUpdate()->first();

            if ($config === null) {
                return;
            }

            $before = $this->save->snapshot($config);
            $config->cars()->detach();
            $config->delete();

            $this->recordAudit->handle($user, 'default_config.deleted', $config, $before, null);
        });
    }
}
