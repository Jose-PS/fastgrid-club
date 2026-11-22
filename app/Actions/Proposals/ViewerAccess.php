<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\User;
use App\Models\Week;

class ViewerAccess
{
    public function state(?User $user): string
    {
        return match (true) {
            $user === null => 'guest',
            ! $user->hasVotingIdentity() => 'needs_identity',
            default => 'ready',
        };
    }

    /**
     * @return array<int, int>
     */
    public function ownProposalIds(?Week $week, ?User $user): array
    {
        if ($week === null || $user === null || $this->state($user) !== 'ready') {
            return [];
        }

        return Proposal::query()
            ->where('week_id', $week->id)
            ->where('user_id', $user->id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $ownIds
     */
    public function limitReached(string $state, array $ownIds): bool
    {
        return $state === 'ready' && count($ownIds) >= (int) config('mvp.max_proposals_per_user');
    }
}
