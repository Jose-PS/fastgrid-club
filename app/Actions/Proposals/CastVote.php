<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\VoteResult;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CastVote
{
    public function __construct(private readonly ProposalListing $listing) {}

    public function handle(User $user, Proposal $proposal): VoteResult
    {
        if (! $user->hasVotingIdentity()) {
            return VoteResult::Unavailable;
        }

        $result = DB::transaction(function () use ($user, $proposal): VoteResult {
            $week = Week::query()->whereKey($proposal->week_id)->sharedLock()->first();

            if ($week === null || ! $week->acceptsVotesAt(CarbonImmutable::now())) {
                return VoteResult::Closed;
            }

            $target = Proposal::query()->whereKey($proposal->id)->sharedLock()->first();

            if ($target === null || $target->isHidden()) {
                return VoteResult::Unavailable;
            }

            return $this->place($week, $target, $user);
        });

        if (in_array($result, [VoteResult::Voted, VoteResult::Moved], true)) {
            $this->listing->forget($proposal->week_id);
        }

        return $result;
    }

    private function place(Week $week, Proposal $proposal, User $user): VoteResult
    {
        $existing = $this->existingVote($week, $user);

        if ($existing === null) {
            $now = CarbonImmutable::now();

            $inserted = Vote::query()->insertOrIgnore([
                'week_id' => $week->id,
                'proposal_id' => $proposal->id,
                'user_id' => $user->id,
                'steam_id' => $user->steam_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return VoteResult::Voted;
            }

            $existing = $this->existingVote($week, $user);

            if ($existing === null) {
                return VoteResult::Unavailable;
            }
        }

        if ($existing->proposal_id === $proposal->id) {
            return VoteResult::Unchanged;
        }

        $existing->update(['proposal_id' => $proposal->id, 'steam_id' => $user->steam_id]);

        return VoteResult::Moved;
    }

    private function existingVote(Week $week, User $user): ?Vote
    {
        return Vote::query()
            ->where('week_id', $week->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }
}
