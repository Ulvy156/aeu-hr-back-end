<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('announcements.view') || $user->hasPermissionTo('announcements.view_draft');
    }

    public function view(User $user, Announcement $announcement): bool
    {
        if ($user->hasPermissionTo('announcements.view_draft')) {
            return true;
        }

        if (! $user->hasPermissionTo('announcements.view')) {
            return false;
        }

        return $announcement->status === 'published' && $this->isTargeted($user, $announcement);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('announcements.create');
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('announcements.update')
            && in_array($announcement->status, ['draft', 'rejected'], true);
    }

    public function submit(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('announcements.submit')
            && in_array($announcement->status, ['draft', 'rejected'], true);
    }

    public function cancelSubmission(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('announcements.cancel_submission')
            && $announcement->status === 'pending_approval'
            && $announcement->created_by === $user->id;
    }

    public function approve(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('announcements.approve')
            && $announcement->status === 'pending_approval'
            && $announcement->created_by !== $user->id;
    }

    public function reject(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('announcements.approve')
            && $announcement->status === 'pending_approval'
            && $announcement->created_by !== $user->id;
    }

    public function archive(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('announcements.archive')
            && $announcement->status === 'published';
    }

    protected function isTargeted(User $user, Announcement $announcement): bool
    {
        $employee = $user->loadMissing('employee')->employee;

        if (! $employee) {
            return false;
        }

        $roleIds = $user->roles()->pluck('roles.id')->all();
        $departmentId = $employee->department_id;
        $employeeId = $employee->id;

        return $announcement->targets()
            ->where(function (Builder $query) use ($roleIds, $departmentId, $employeeId) {
                $query
                    ->where('target_type', 'all')
                    ->orWhere(function (Builder $inner) use ($employeeId) {
                        $inner->where('target_type', 'employee')->where('target_id', $employeeId);
                    });

                if ($departmentId) {
                    $query->orWhere(function (Builder $inner) use ($departmentId) {
                        $inner->where('target_type', 'department')->where('target_id', $departmentId);
                    });
                }

                if (! empty($roleIds)) {
                    $query->orWhere(function (Builder $inner) use ($roleIds) {
                        $inner->where('target_type', 'role')->whereIn('target_id', $roleIds);
                    });
                }
            })
            ->exists();
    }
}
