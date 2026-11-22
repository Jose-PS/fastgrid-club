<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Proposals\HideProposal;
use App\Actions\Proposals\ShowProposal;
use App\Enums\WeekStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HideProposalRequest;
use App\Models\Car;
use App\Models\Proposal;
use App\Models\Server;
use App\Models\User;
use App\Models\Week;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProposalsController extends Controller
{
    public function index(Translations $translations): Response
    {
        $server = Server::query()->where('is_active', true)->orderBy('id')->first();
        $week = $server === null ? null : $server->weeks()->where('status', WeekStatus::Voting)->orderBy('starts_at')->first();

        return Inertia::render('admin/proposals', [
            'pageTranslations' => $translations->forGroups(['admin']),
            'week' => $week === null ? null : [
                'id' => $week->id,
                'starts_at' => $week->starts_at->toIso8601String(),
                'ends_at' => $week->ends_at->toIso8601String(),
            ],
            'proposals' => $week === null ? [] : $this->proposals($week),
        ]);
    }

    public function hide(HideProposalRequest $request, Proposal $proposal, HideProposal $hideProposal): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $hideProposal->handle($user, $proposal, $request->reason());

        return back();
    }

    public function show(Request $request, Proposal $proposal, ShowProposal $showProposal): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $showProposal->handle($user, $proposal);

        return back();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposals(Week $week): array
    {
        return $week->proposals()
            ->with(['track:id,display', 'cars:id,display_name', 'user:id,name'])
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Proposal $proposal): array => [
                'id' => $proposal->id,
                'track' => $proposal->track->display,
                'cars' => $proposal->cars->sortBy('display_name')->map(fn (Car $car): string => $car->display_name)->values()->all(),
                'author' => $proposal->user === null ? null : ['id' => $proposal->user->id, 'name' => $proposal->user->name],
                'votes' => (int) $proposal->votes_count,
                'hidden' => $proposal->isHidden(),
                'hidden_reason' => $proposal->hidden_reason,
            ])
            ->all();
    }
}
