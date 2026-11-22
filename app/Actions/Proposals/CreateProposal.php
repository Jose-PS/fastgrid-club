<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Models\Proposal;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProposal
{
    public function __construct(
        private readonly TargetWeek $targetWeek,
        private readonly ProposalListing $listing,
    ) {}

    /**
     * @param  array<int, int>  $carIds
     */
    public function handle(User $user, int $trackId, array $carIds): Proposal
    {
        $signature = Proposal::signatureFor($trackId, $carIds);

        try {
            $proposal = DB::transaction(function () use ($user, $trackId, $carIds, $signature): Proposal {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $now = CarbonImmutable::now();
                $target = $this->targetWeek->find($now);
                $week = $target === null ? null : Week::query()->whereKey($target->id)->sharedLock()->first();

                if ($week === null || ! $week->acceptsVotesAt($now)) {
                    throw ValidationException::withMessages(['proposal' => __('props.closed')]);
                }

                $proposed = Proposal::query()
                    ->where('week_id', $week->id)
                    ->where('user_id', $user->id)
                    ->count();

                if ($proposed >= (int) config('mvp.max_proposals_per_user')) {
                    throw ValidationException::withMessages(['proposal' => __('props.quota')]);
                }

                $existing = Proposal::query()->where('week_id', $week->id)->where('signature', $signature)->first();

                if ($existing !== null) {
                    throw $this->duplicate($existing->isHidden());
                }

                $proposal = Proposal::query()->create([
                    'week_id' => $week->id,
                    'user_id' => $user->id,
                    'track_id' => $trackId,
                    'signature' => $signature,
                ]);

                $proposal->cars()->attach(array_values(array_unique($carIds)));

                return $proposal;
            });
        } catch (UniqueConstraintViolationException) {
            $weekId = $this->targetWeek->find(CarbonImmutable::now())?->id;
            $existing = $weekId === null
                ? null
                : Proposal::query()->where('week_id', $weekId)->where('signature', $signature)->first();

            throw $this->duplicate($existing?->isHidden() ?? false);
        }

        $this->listing->forget($proposal->week_id);

        return $proposal;
    }

    private function duplicate(bool $hidden): ValidationException
    {
        return ValidationException::withMessages([
            'proposal' => __($hidden ? 'props.duplicateHidden' : 'props.duplicate'),
        ]);
    }
}
