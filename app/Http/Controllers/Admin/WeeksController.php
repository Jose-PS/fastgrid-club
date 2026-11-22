<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Proposals\ProposalListing;
use App\Actions\Weeks\ChooseWeekWinner;
use App\Actions\Weeks\ForceWeek;
use App\Enums\ConfigSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChooseWeekWinnerRequest;
use App\Http\Requests\UpdateDefaultConfigRequest;
use App\Models\Car;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\User;
use App\Models\Week;
use App\Support\AdminServerStatus;
use App\Support\Translations;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WeeksController extends Controller
{
    private const int LIMIT = 20;

    public function index(Translations $translations, ProposalListing $listing, ForceWeek $forceWeek, AdminServerStatus $status): Response
    {
        $server = Server::query()->where('is_active', true)->orderBy('id')->first();
        $now = CarbonImmutable::now();

        $weeks = $server === null ? collect() : $server->weeks()
            ->with(['track:id,display', 'cars:id,display_name'])
            ->orderByDesc('starts_at')
            ->limit(self::LIMIT)
            ->get();

        return Inertia::render('admin/weeks', [
            'pageTranslations' => $translations->forGroups(['admin', 'props']),
            'options' => $listing->options(),
            'live' => $server === null ? null : [
                'players' => $status->live($server, $now)['players'],
                'paused' => $server->planning_paused,
            ],
            'weeks' => $weeks->map(fn (Week $week): array => $this->week($week, $now, $forceWeek))->values()->all(),
        ]);
    }

    public function update(UpdateDefaultConfigRequest $request, Week $week, ForceWeek $forceWeek): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $this->ensureActiveServer($week);

        $forceWeek->handle($user, $week, $request->integer('track_id'), $request->carIds(), $request->customTitle());

        return back();
    }

    public function winner(ChooseWeekWinnerRequest $request, Week $week, ChooseWeekWinner $chooseWeekWinner): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $this->ensureActiveServer($week);

        $chooseWeekWinner->handle($user, $week, $request->integer('proposal_id'));

        return back();
    }

    private function ensureActiveServer(Week $week): void
    {
        abort_unless(Server::query()->whereKey($week->server_id)->where('is_active', true)->exists(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function week(Week $week, CarbonImmutable $now, ForceWeek $forceWeek): array
    {
        $over = $forceWeek->isOver($week, $now);
        $canChoose = ! $over && $week->resolved_at !== null && $week->config_source === ConfigSource::Fallback;

        return [
            'id' => $week->id,
            'starts_at' => $week->starts_at->toIso8601String(),
            'ends_at' => $week->ends_at->toIso8601String(),
            'status' => $week->status->value,
            'config_source' => $week->config_source?->value,
            'custom_title' => $week->custom_title,
            'track_id' => $week->track_id,
            'track' => $week->track?->display,
            'car_ids' => $week->cars->pluck('id')->sort()->values()->all(),
            'cars' => $week->cars->sortBy('display_name')->map(fn (Car $car): string => $car->display_name)->values()->all(),
            'is_current' => $week->starts_at <= $now && $now < $week->ends_at,
            'editable' => ! $over,
            'proposals' => $canChoose ? $this->candidates($week) : null,
        ];
    }

    /**
     * @return array<int, array{id: int, track: string, cars: array<int, string>, votes: int}>
     */
    private function candidates(Week $week): array
    {
        return $week->proposals()
            ->whereNull('hidden_at')
            ->with(['track:id,display', 'cars:id,display_name'])
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Proposal $proposal): array => [
                'id' => $proposal->id,
                'track' => $proposal->track->display,
                'cars' => $proposal->cars->sortBy('display_name')->map(fn (Car $car): string => $car->display_name)->values()->all(),
                'votes' => (int) $proposal->votes_count,
            ])
            ->all();
    }
}
