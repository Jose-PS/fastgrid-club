<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proposals;

use App\Actions\Proposals\CastVote;
use App\Enums\VoteResult;
use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Models\User;
use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class VoteController extends Controller
{
    public function store(Request $request, Proposal $proposal, CastVote $castVote): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $castVote->handle($user, $proposal);

        if (! $result->counted()) {
            throw ValidationException::withMessages([
                'vote' => __($result === VoteResult::Closed ? 'props.closed' : 'props.unavailable'),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __($result === VoteResult::Moved ? 'props.moved' : 'props.voted'),
        ]);

        return redirect()->route(PublicRoutes::name('proposals', PublicRoutes::localeFromReferer($request)));
    }
}
