<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('employees.view_any');
    }

    public function search(User $user): bool
    {
        return $user->hasPermissionTo('employees.search');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('employees.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('employees.update');
    }

    public function updateSalary(User $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('employees.update_salary');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->hasPermissionTo('employees.delete');
    }
}
