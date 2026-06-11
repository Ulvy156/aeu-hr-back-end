<?php

namespace App\Policies;

use App\Models\RecruitmentVacancy;
use App\Models\User;

class RecruitmentVacancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.vacancies.view');
    }

    public function view(User $user, RecruitmentVacancy $vacancy): bool
    {
        return $user->hasPermissionTo('recruitment.vacancies.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('recruitment.vacancies.create');
    }

    public function update(User $user, RecruitmentVacancy $vacancy): bool
    {
        return $user->hasPermissionTo('recruitment.vacancies.update');
    }

    public function close(User $user, RecruitmentVacancy $vacancy): bool
    {
        return $user->hasPermissionTo('recruitment.vacancies.close')
            && $vacancy->status === 'open';
    }
}
