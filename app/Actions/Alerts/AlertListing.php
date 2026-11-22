<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Integration\RecordIntegrationFailure;
use App\Enums\AlertType;
use App\Enums\IntegrationEventType;
use App\Models\Alert;
use App\Models\IntegrationEvent;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class AlertListing
{
    /**
     * @return list<array{id: int, type: string, summary: string, opened_at: string, sources: list<string>}>
     */
    public function open(): array
    {
        return array_values(Alert::query()
            ->open()
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'type' => $alert->type->value,
                'summary' => $alert->localizedSummary(),
                'opened_at' => $alert->opened_at->toIso8601String(),
                'sources' => $alert->type === AlertType::IntegrationFailing ? $this->sources($alert) : [],
            ])
            ->all());
    }

    /**
     * @return list<string>
     */
    private function sources(Alert $alert): array
    {
        $server = $alert->server_id === null ? null : Server::query()->find($alert->server_id);

        if ($server === null) {
            return [];
        }

        $failing = [];

        foreach (IntegrationEventType::cases() as $type) {
            if ((int) Cache::get($type->failuresKey($server), 0) >= RecordIntegrationFailure::THRESHOLD) {
                $failing[] = $type->value;
            }
        }

        if ($failing !== []) {
            return $failing;
        }

        $last = IntegrationEvent::query()->where('server_id', $server->id)->orderByDesc('id')->first();

        return $last === null ? [] : [$last->type->value];
    }
}
