<?php

namespace App\Policies;

use App\Models\RecruitmentCandidate;
use App\Models\User;

class RecruitmentCandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.view');
    }

    public function view(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.create');
    }

    public function update(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.update')
            && $candidate->status !== 'hired';
    }

    public function updateStatus(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.update')
            && $candidate->status !== 'hired';
    }

    public function hire(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->hasPermissionTo('recruitment.candidates.hire');
    }
}
