<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proposals;

use App\Actions\Proposals\CreateProposal;
use App\Actions\Proposals\WithdrawProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProposalRequest;
use App\Models\Proposal;
use App\Models\User;
use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProposalController extends Controller
{
    public function store(StoreProposalRequest $request, CreateProposal $createProposal): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $createProposal->handle($user, (int) $request->validated('track_id'), $request->carIds());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('props.added')]);

        return redirect()->route(PublicRoutes::name('proposals', PublicRoutes::localeFromReferer($request)));
    }

    public function destroy(Request $request, Proposal $proposal, WithdrawProposal $withdrawProposal): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $withdrawProposal->handle($user, $proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('props.withdrawn')]);

        return redirect()->route(PublicRoutes::name('proposals', PublicRoutes::localeFromReferer($request)));
    }
}
