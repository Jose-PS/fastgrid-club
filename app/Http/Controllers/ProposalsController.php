<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Proposals\LastVoting;
use App\Actions\Proposals\ProposalListing;
use App\Actions\Proposals\TargetWeek;
use App\Actions\Proposals\ViewerAccess;
use App\Actions\Proposals\WeekPayload;
use App\Models\User;
use App\Models\Vote;
use App\Models\Week;
use App\Support\Translations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProposalsController extends Controller
{
    public function __invoke(
        Request $request,
        Translations $translations,
        TargetWeek $targetWeek,
        ProposalListing $listing,
        ViewerAccess $access,
        WeekPayload $weekPayload,
        LastVoting $lastVoting,
    ): Response {
        $now = CarbonImmutable::now();
        $week = $targetWeek->find($now);
        $proposals = $week === null ? [] : $listing->forWeek($week);
        $isOpen = $week !== null && $week->acceptsVotesAt($now);

        /** @var User|null $user */
        $user = $request->user();
        $state = $access->state($user);

        $vote = $week !== null && $user !== null ? $this->voteOf($week, $user) : null;
        $ownIds = $access->ownProposalIds($week, $user);

        return Inertia::render('proposals', [
            'pageTranslations' => $translations->forGroups(['props']),
            'serverNow' => $weekPayload->iso($now),
            'week' => $week === null ? null : $weekPayload->make($week, $now),
            'total' => count($proposals),
            'proposals' => array_map(function (array $proposal) use ($vote, $ownIds, $isOpen): array {
                $isMine = in_array($proposal['id'], $ownIds, true);
                $ownVote = $vote !== null && $vote['proposalId'] === $proposal['id'] ? 1 : 0;

                return [
                    ...$proposal,
                    'isMine' => $isMine,
                    'canWithdraw' => $isOpen && $isMine && $proposal['votes'] - $ownVote === 0,
                ];
            }, $proposals),
            'viewer' => [
                'state' => $state,
                'vote' => $vote,
                'proposalLimitReached' => $access->limitReached($state, $ownIds),
            ],
            'options' => $listing->options(),
            'lastVote' => $lastVoting->find(),
        ]);
    }

    /**
     * @return array{proposalId: int, hidden: bool}|null
     */
    private function voteOf(Week $week, User $user): ?array
    {
        $vote = Vote::query()
            ->join('proposals', 'proposals.id', '=', 'votes.proposal_id')
            ->where('votes.week_id', $week->id)
            ->where('votes.user_id', $user->id)
            ->first(['votes.proposal_id', 'proposals.hidden_at']);

        return $vote === null ? null : [
            'proposalId' => (int) $vote->proposal_id,
            'hidden' => $vote->getAttribute('hidden_at') !== null,
        ];
    }
}
