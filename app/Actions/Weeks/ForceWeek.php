<?php

declare(strict_types=1);

namespace App\Actions\Weeks;

use App\Actions\Alerts\CloseAlert;
use App\Actions\Audit\RecordAudit;
use App\Actions\Proposals\ProposalListing;
use App\Enums\AlertType;
use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ForceWeek
{
    public function __construct(
        private readonly ApplyWeekConfig $applyWeekConfig,
        private readonly RecordAudit $recordAudit,
        private readonly ProposalListing $listing,
        private readonly CloseAlert $closeAlert,
    ) {}

    /**
     * @param  array<int, int>  $carIds
     */
    public function handle(User $admin, Week $week, int $trackId, array $carIds, ?string $customTitle, ?CarbonImmutable $now = null): Week
    {
        $now ??= CarbonImmutable::now();

        $week = DB::transaction(function () use ($admin, $week, $trackId, $carIds, $customTitle, $now): Week {
            $locked = Week::query()->whereKey($week->id)->lockForUpdate()->firstOrFail();

            if ($this->isOver($locked, $now)) {
                throw ValidationException::withMessages(['week' => __('admin.weeks.errFinished')]);
            }

            $before = $this->snapshot($locked);

            $this->applyWeekConfig->handle(
                $locked,
                Server::query()->findOrFail($locked->server_id),
                Track::query()->findOrFail($trackId),
                $carIds,
                [
                    'custom_title' => $customTitle,
                    'config_source' => ConfigSource::Admin,
                    'proposal_id' => null,
                    'resolved_at' => $locked->resolved_at ?? ($locked->status === WeekStatus::Voting ? null : $now),
                ],
            );

            $this->closeAlert->forSubject(AlertType::WeekFallbackPendingReview, $locked);
            $this->recordAudit->handle($admin, 'week.forced', $locked, $before, $this->snapshot($locked));

            return $locked;
        });

        $this->listing->forget($week->id);

        return $week;
    }

    public function isOver(Week $week, CarbonImmutable $now): bool
    {
        return $week->status === WeekStatus::Finished || $week->ends_at <= $now;
    }

    /**
     * @return array{track_id: int|null, car_ids: array<int, int>, custom_title: string|null, config_source: string|null, proposal_id: int|null}
     */
    public function snapshot(Week $week): array
    {
        return [
            'track_id' => $week->track_id,
            'car_ids' => $week->cars()->orderBy('cars.id')->pluck('cars.id')->map(fn ($id): int => (int) $id)->all(),
            'custom_title' => $week->custom_title,
            'config_source' => $week->config_source?->value,
            'proposal_id' => $week->proposal_id,
        ];
    }
}
