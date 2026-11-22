<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\VoteResult;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Inertia;

class VoteIntent
{
    private const string SessionKey = 'proposals.vote_intent';

    public function __construct(private readonly CastVote $castVote) {}

    public function remember(Proposal $proposal): void
    {
        session()->put(self::SessionKey, [
            'proposal_id' => $proposal->id,
            'expires_at' => CarbonImmutable::now()->addMinutes((int) config('mvp.vote_intent.ttl_minutes'))->getTimestamp(),
        ]);
    }

    public function apply(User $user): ?VoteResult
    {
        $intent = session()->get(self::SessionKey);

        if (! is_array($intent) || ! is_int($intent['proposal_id'] ?? null) || ! is_int($intent['expires_at'] ?? null)) {
            session()->forget(self::SessionKey);

            return null;
        }

        if (CarbonImmutable::now()->getTimestamp() >= $intent['expires_at']) {
            session()->forget(self::SessionKey);

            return null;
        }

        $user->load(['steamIdentity', 'googleIdentity']);

        if (! $user->hasVotingIdentity()) {
            return null;
        }

        session()->forget(self::SessionKey);

        $proposal = Proposal::query()->find($intent['proposal_id']);

        if ($proposal === null) {
            return null;
        }

        $result = $this->castVote->handle($user, $proposal);

        if ($result->counted()) {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __($result === VoteResult::Moved ? 'props.moved' : 'props.voted'),
            ]);
        }

        return $result;
    }
}
