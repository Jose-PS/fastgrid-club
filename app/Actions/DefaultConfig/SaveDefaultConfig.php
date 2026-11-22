<?php

declare(strict_types=1);

namespace App\Actions\DefaultConfig;

use App\Actions\Audit\RecordAudit;
use App\Models\Server;
use App\Models\ServerDefaultConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveDefaultConfig
{
    public function __construct(private readonly RecordAudit $recordAudit) {}

    /**
     * @param  array<int, int>  $carIds
     */
    public function handle(User $user, Server $server, int $trackId, array $carIds, ?string $customTitle): ServerDefaultConfig
    {
        return DB::transaction(function () use ($user, $server, $trackId, $carIds, $customTitle): ServerDefaultConfig {
            $config = ServerDefaultConfig::query()->where('server_id', $server->id)->lockForUpdate()->first();
            $before = $config === null ? null : $this->snapshot($config);

            $config ??= new ServerDefaultConfig(['server_id' => $server->id]);
            $config->fill(['track_id' => $trackId, 'custom_title' => $customTitle])->save();
            $config->cars()->sync(array_values(array_unique($carIds)));

            $after = $this->snapshot($config);

            if ($before !== $after) {
                $this->recordAudit->handle($user, 'default_config.saved', $config, $before, $after);
            }

            return $config;
        });
    }

    /**
     * @return array{track_id: int|null, car_ids: array<int, int>, custom_title: string|null}
     */
    public function snapshot(ServerDefaultConfig $config): array
    {
        return [
            'track_id' => $config->track_id,
            'car_ids' => $config->cars()->orderBy('cars.id')->pluck('cars.id')->map(fn ($id): int => (int) $id)->all(),
            'custom_title' => $config->custom_title,
        ];
    }
}
