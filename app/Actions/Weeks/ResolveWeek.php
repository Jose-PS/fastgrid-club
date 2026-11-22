<?php

declare(strict_types=1);

namespace App\Actions\Weeks;

use App\Actions\Alerts\OpenAlert;
use App\Actions\Proposals\ProposalListing;
use App\Enums\AlertType;
use App\Enums\ConfigSource;
use App\Enums\WeekStatus;
use App\Mail\WeekWithoutWinnerMail;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\Track;
use App\Models\User;
use App\Models\Week;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ResolveWeek
{
    public function __construct(private readonly ProposalListing $listing, private readonly ApplyWeekConfig $applyWeekConfig, private readonly OpenAlert $openAlert) {}

    public function due(?CarbonImmutable $now = null, ?int $serverId = null): int
    {
        $now ??= CarbonImmutable::now();

        $ids = Week::query()
            ->where('status', WeekStatus::Voting)
            ->where('voting_closes_at', '<', $now)
            ->when($serverId !== null, fn ($query) => $query->where('server_id', $serverId))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->pluck('id');

        $resolved = 0;

        foreach ($ids as $id) {
            if ($this->handle((int) $id, $now)) {
                $resolved++;
            }
        }

        return $resolved;
    }

    public function handle(int $weekId, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        $outcome = DB::transaction(function () use ($weekId, $now): ?array {
            $week = Week::query()->whereKey($weekId)->lockForUpdate()->first();

            if ($week === null || $week->status !== WeekStatus::Voting || $week->acceptsVotesAt($now)) {
                return null;
            }

            if ($week->config_source === ConfigSource::Admin) {
                $week->update(['resolved_at' => $week->resolved_at ?? $now, 'status' => WeekStatus::Scheduled]);

                return ['week' => $week, 'source' => ConfigSource::Admin];
            }

            $server = Server::query()->findOrFail($week->server_id);
            $winner = $this->winner($week);

            if ($winner !== null) {
                $this->applyProposal($week, $server, $winner, $now);

                return ['week' => $week, 'source' => ConfigSource::Proposal];
            }

            $source = $this->applyWithoutWinner($week, $server, $now);

            return ['week' => $week, 'source' => $source];
        });

        if ($outcome === null) {
            return false;
        }

        $this->listing->forget($weekId);

        if ($outcome['source'] === ConfigSource::Fallback) {
            $this->openAlert->handle(
                AlertType::WeekFallbackPendingReview,
                'admin.alerts.weekFallback',
                ['date' => $outcome['week']->starts_at->copy()->timezone((string) config('mvp.timezone'))->format('d/m/Y')],
                $outcome['week'],
                $outcome['week']->server_id,
            );
            $this->notifyAdmins($outcome['week'], $outcome['source']);
        }

        return true;
    }

    private function winner(Week $week): ?Proposal
    {
        $top = Proposal::query()
            ->where('week_id', $week->id)
            ->whereNull('hidden_at')
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $top !== null && (int) $top->votes_count >= 1 ? $top : null;
    }

    private function applyProposal(Week $week, Server $server, Proposal $winner, CarbonImmutable $now): void
    {
        $track = Track::query()->findOrFail($winner->track_id);

        $this->applyWeekConfig->handle($week, $server, $track, $winner->cars()->pluck('cars.id')->all(), [
            'config_source' => ConfigSource::Proposal,
            'proposal_id' => $winner->id,
            'resolved_at' => $now,
            'status' => WeekStatus::Scheduled,
        ]);
    }

    private function applyWithoutWinner(Week $week, Server $server, CarbonImmutable $now): ConfigSource
    {
        $default = $server->defaultConfig;

        if ($default === null || $default->track_id === null) {
            $week->update([
                'track_id' => null,
                'max_players' => null,
                'config_source' => ConfigSource::Fallback,
                'proposal_id' => null,
                'resolved_at' => $now,
                'status' => WeekStatus::Scheduled,
            ]);

            return ConfigSource::Fallback;
        }

        $track = Track::query()->findOrFail($default->track_id);

        $this->applyWeekConfig->handle($week, $server, $track, $default->cars()->pluck('cars.id')->all(), [
            'custom_title' => $default->custom_title,
            'config_source' => ConfigSource::Fallback,
            'proposal_id' => null,
            'resolved_at' => $now,
            'status' => WeekStatus::Scheduled,
        ]);

        return ConfigSource::Fallback;
    }

    private function notifyAdmins(Week $week, ConfigSource $source): void
    {
        User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->each(fn (User $admin) => Mail::to($admin)->send(new WeekWithoutWinnerMail($week, $source)));
    }
}
