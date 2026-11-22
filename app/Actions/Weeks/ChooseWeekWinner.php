<?php

declare(strict_types=1);

namespace App\Actions\Weeks;

use App\Actions\Alerts\CloseAlert;
use App\Actions\Audit\RecordAudit;
use App\Actions\Proposals\ProposalListing;
use App\Enums\AlertType;
use App\Enums\ConfigSource;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChooseWeekWinner
{
    public function __construct(
        private readonly ApplyWeekConfig $applyWeekConfig,
        private readonly ForceWeek $forceWeek,
        private readonly RecordAudit $recordAudit,
        private readonly ProposalListing $listing,
        private readonly CloseAlert $closeAlert,
    ) {}

    public function handle(User $admin, Week $week, int $proposalId, ?CarbonImmutable $now = null): Week
    {
        $now ??= CarbonImmutable::now();

        $week = DB::transaction(function () use ($admin, $week, $proposalId, $now): Week {
            $locked = Week::query()->whereKey($week->id)->lockForUpdate()->firstOrFail();

            if ($this->forceWeek->isOver($locked, $now)) {
                throw ValidationException::withMessages(['week' => __('admin.weeks.errFinished')]);
            }

            if ($locked->resolved_at === null || $locked->config_source !== ConfigSource::Fallback) {
                throw ValidationException::withMessages(['week' => __('admin.weeks.errNoFallback')]);
            }

            $proposal = Proposal::query()->whereKey($proposalId)->where('week_id', $locked->id)->whereNull('hidden_at')->first();

            if ($proposal === null) {
                throw ValidationException::withMessages(['proposal_id' => __('admin.weeks.errProposal')]);
            }

            $before = $this->forceWeek->snapshot($locked);

            $this->applyWeekConfig->handle(
                $locked,
                Server::query()->findOrFail($locked->server_id),
                Track::query()->findOrFail($proposal->track_id),
                $proposal->cars()->pluck('cars.id')->all(),
                [
                    'custom_title' => null,
                    'config_source' => ConfigSource::Admin,
                    'proposal_id' => $proposal->id,
                ],
            );

            $this->closeAlert->forSubject(AlertType::WeekFallbackPendingReview, $locked);
            $this->recordAudit->handle($admin, 'week.winner_chosen', $locked, $before, $this->forceWeek->snapshot($locked));

            return $locked;
        });

        $this->listing->forget($week->id);

        return $week;
    }
}
