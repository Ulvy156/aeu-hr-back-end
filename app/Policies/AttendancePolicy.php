<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('attendance.view_any');
    }

    public function viewOwn(User $user): bool
    {
        return $user->hasPermissionTo('attendance.view_own');
    }

    public function clockIn(User $user): bool
    {
        return $user->hasPermissionTo('attendance.clock_in');
    }

    public function clockOut(User $user): bool
    {
        return $user->hasPermissionTo('attendance.clock_out');
    }

    public function correct(User $user, Attendance $attendance): bool
    {
        return $user->hasPermissionTo('attendance.correct');
    }

    public function markAbsent(User $user): bool
    {
        return $user->hasPermissionTo('attendance.mark_absent');
    }

    public function proxyClock(User $user): bool
    {
        return $user->hasPermissionTo('attendance.proxy_clock');
    }

    public function generateQr(User $user): bool
    {
        return $user->hasPermissionTo('attendance.generate_qr');
    }
}
