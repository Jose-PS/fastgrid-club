<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\WeekStatus;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class TargetWeek
{
    public function find(?CarbonImmutable $now = null): ?Week
    {
        $now ??= CarbonImmutable::now();

        return Week::query()
            ->whereHas('server', fn (Builder $query) => $query->where('is_active', true))
            ->where('status', WeekStatus::Voting)
            ->where('voting_closes_at', '>', $now->subSecond())
            ->orderBy('starts_at')
            ->orderBy('server_id')
            ->first();
    }
}
