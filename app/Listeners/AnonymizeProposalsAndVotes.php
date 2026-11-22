<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Proposals\ProposalListing;
use App\Enums\WeekStatus;
use App\Events\AccountDeleted;
use App\Events\SteamUnlinked;
use App\Models\Proposal;
use App\Models\Vote;
use App\Models\Week;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AnonymizeProposalsAndVotes
{
    public function __construct(private readonly ProposalListing $listing) {}

    public function handleAccountDeleted(AccountDeleted $event): void
    {
        $this->anonymize(
            fn (Builder $query) => $query->whereNull('user_id'),
            $event->steamId,
            closedProposalsToo: false,
            orphanedOpenVotesToo: true,
        );
    }

    public function handleSteamUnlinked(SteamUnlinked $event): void
    {
        $this->anonymize(
            fn (Builder $query) => $query->where('user_id', $event->userId),
            $event->steamId,
            closedProposalsToo: true,
            orphanedOpenVotesToo: false,
        );
    }

    /**
     * @param  callable(Builder<Proposal>): mixed  $authoredBy
     */
    private function anonymize(callable $authoredBy, ?string $steamId, bool $closedProposalsToo, bool $orphanedOpenVotesToo): void
    {
        $openWeekIds = Week::query()->where('status', WeekStatus::Voting)->pluck('id')->all();

        DB::transaction(function () use ($authoredBy, $steamId, $closedProposalsToo, $orphanedOpenVotesToo, $openWeekIds): void {
            if ($steamId !== null) {
                Vote::query()->where('steam_id', $steamId)->whereIn('week_id', $openWeekIds)->delete();
                Vote::query()->where('steam_id', $steamId)->whereNotIn('week_id', $openWeekIds)->update(['steam_id' => null, 'user_id' => null]);
            }

            if ($orphanedOpenVotesToo) {
                Vote::query()->whereNull('user_id')->whereIn('week_id', $openWeekIds)->delete();
            }

            $authoredBy(Proposal::query()->whereIn('week_id', $openWeekIds))->delete();

            if ($closedProposalsToo) {
                $authoredBy(Proposal::query()->whereNotIn('week_id', $openWeekIds))->update(['user_id' => null]);
            }
        });

        foreach ($openWeekIds as $weekId) {
            $this->listing->forget($weekId);
        }
    }
}
