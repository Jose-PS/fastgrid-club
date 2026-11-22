<?php

declare(strict_types=1);

namespace App\Actions\Integration;

use App\Actions\Alerts\CloseAlert;
use App\Enums\AlertType;
use App\Enums\IntegrationEventType;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class RecordIntegrationSuccess
{
    public function __construct(private readonly CloseAlert $closeAlert) {}

    public function handle(Server $server, IntegrationEventType $type): void
    {
        Cache::forget($type->failuresKey($server));

        if ($this->stillFailing($server)) {
            return;
        }

        $this->closeAlert->forSubject(AlertType::IntegrationFailing, $server);
    }

    private function stillFailing(Server $server): bool
    {
        foreach (IntegrationEventType::cases() as $other) {
            if ((int) Cache::get($other->failuresKey($server), 0) >= RecordIntegrationFailure::THRESHOLD) {
                return true;
            }
        }

        return false;
    }
}
