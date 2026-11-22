<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawProposal
{
    public function __construct(private readonly ProposalListing $listing) {}

    public function handle(User $user, Proposal $proposal): void
    {
        DB::transaction(function () use ($user, $proposal): void {
            $week = Week::query()->whereKey($proposal->week_id)->sharedLock()->first();

            if ($week === null || ! $week->acceptsVotesAt(CarbonImmutable::now())) {
                throw ValidationException::withMessages(['proposal' => __('props.closed')]);
            }

            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($locked->hasVotesFromOthers($user->id)) {
                throw ValidationException::withMessages(['proposal' => __('props.withdrawBlocked')]);
            }

            $locked->delete();
        });

        $this->listing->forget($proposal->week_id);
    }
}
