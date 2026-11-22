<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProposalPolicy
{
    public function create(User $user): Response
    {
        return $this->requireVotingIdentity($user);
    }

    public function vote(User $user, Proposal $proposal): Response
    {
        return $this->requireVotingIdentity($user);
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        return $proposal->user_id === $user->id
            && ! $proposal->isHidden()
            && ! $proposal->hasVotesFromOthers($user->id);
    }

    public function hide(User $user, Proposal $proposal): bool
    {
        return $user->is_admin;
    }

    private function requireVotingIdentity(User $user): Response
    {
        return $user->hasVotingIdentity() ? Response::allow() : Response::deny(__('props.needsIdentity'));
    }
}
