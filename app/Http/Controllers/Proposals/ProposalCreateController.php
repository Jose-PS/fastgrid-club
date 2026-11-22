<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proposals;

use App\Actions\Proposals\ProposalListing;
use App\Actions\Proposals\TargetWeek;
use App\Actions\Proposals\ViewerAccess;
use App\Actions\Proposals\WeekPayload;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Translations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProposalCreateController extends Controller
{
    public function __invoke(
        Request $request,
        Translations $translations,
        TargetWeek $targetWeek,
        ProposalListing $listing,
        ViewerAccess $access,
        WeekPayload $weekPayload,
    ): Response {
        $now = CarbonImmutable::now();
        $week = $targetWeek->find($now);

        /** @var User|null $user */
        $user = $request->user();
        $state = $access->state($user);
        $ownIds = $access->ownProposalIds($week, $user);

        return Inertia::render('proposals/new', [
            'pageTranslations' => $translations->forGroups(['props']),
            'week' => $week === null ? null : $weekPayload->make($week, $now),
            'viewer' => [
                'state' => $state,
                'proposalLimitReached' => $access->limitReached($state, $ownIds),
            ],
            'catalog' => $listing->catalog(),
        ]);
    }
}
