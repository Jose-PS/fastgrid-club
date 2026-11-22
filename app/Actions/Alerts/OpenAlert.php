<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use Illuminate\Database\Eloquent\Model;

class OpenAlert
{
    /**
     * @param  array<string, scalar>  $params
     */
    public function handle(AlertType $type, string $summaryKey, array $params = [], ?Model $subject = null, ?int $serverId = null): Alert
    {
        $attributes = [
            'type' => $type,
            'status' => AlertStatus::Open,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ];

        return Alert::query()->firstOrCreate($attributes, [
            'server_id' => $serverId,
            'summary' => __($summaryKey, $params),
            'summary_key' => $summaryKey,
            'summary_params' => $params,
            'opened_at' => now(),
        ]);
    }
}
