<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Actions\Audit\RecordAudit;
use App\Enums\WeekStatus;
use App\Models\Proposal;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShowProposal
{
    public function __construct(private readonly ProposalListing $listing, private readonly RecordAudit $recordAudit) {}

    public function handle(User $admin, Proposal $proposal): void
    {
        Gate::forUser($admin)->authorize('hide', $proposal);

        DB::transaction(function () use ($admin, $proposal): void {
            $week = Week::query()->whereKey($proposal->week_id)->sharedLock()->first();

            if ($week === null || $week->status !== WeekStatus::Voting) {
                throw ValidationException::withMessages(['proposal' => __('admin.proposals.errNotVoting')]);
            }

            $before = ['hidden' => $proposal->isHidden(), 'reason' => $proposal->hidden_reason];

            $proposal->forceFill([
                'hidden_at' => null,
                'hidden_by' => null,
                'hidden_reason' => null,
            ])->save();

            $this->recordAudit->handle($admin, 'proposal.shown', $proposal, $before, ['hidden' => false, 'reason' => null]);
        });

        $this->listing->forget($proposal->week_id);
    }
}
