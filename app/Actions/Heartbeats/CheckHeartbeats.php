<?php

declare(strict_types=1);

namespace App\Actions\Heartbeats;

use App\Actions\Alerts\CloseAlert;
use App\Actions\Alerts\OpenAlert;
use App\Enums\HeartbeatSource;
use App\Support\Heartbeats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class CheckHeartbeats
{
    public function __construct(
        private readonly Heartbeats $heartbeats,
        private readonly OpenAlert $openAlert,
        private readonly CloseAlert $closeAlert,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stale = [];

        foreach (HeartbeatSource::cases() as $source) {
            $reference = $this->heartbeats->lastBeat($source) ?? $this->watchingSince($source, $now);
            $isStale = $reference->addSeconds($source->staleAfterSeconds())->lessThan($now);

            if ($isStale) {
                $this->openAlert->handle($source->alertType(), $source->alertSummaryKey(), $source->alertSummaryParams());
            } else {
                $this->closeAlert->forType($source->alertType());
            }

            $stale[$source->value] = $isStale;
        }

        return $stale;
    }

    private function watchingSince(HeartbeatSource $source, CarbonImmutable $now): CarbonImmutable
    {
        Cache::add($source->watchingSinceKey(), $now->getTimestamp());

        return CarbonImmutable::createFromTimestamp((int) Cache::get($source->watchingSinceKey(), $now->getTimestamp()), (string) config('app.timezone'));
    }
}
