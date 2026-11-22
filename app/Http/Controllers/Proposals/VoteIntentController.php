<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proposals;

use App\Actions\Auth\LoginRedirect;
use App\Actions\Proposals\VoteIntent;
use App\Http\Controllers\Controller;
use App\Models\Proposal;
use App\Support\PublicRoutes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VoteIntentController extends Controller
{
    public function store(Request $request, Proposal $proposal, VoteIntent $voteIntent, LoginRedirect $loginRedirect): RedirectResponse
    {
        $voteIntent->remember($proposal);
        $loginRedirect->remember(PublicRoutes::path('proposals', PublicRoutes::localeFromReferer($request)));

        return redirect()->route('login');
    }
}
