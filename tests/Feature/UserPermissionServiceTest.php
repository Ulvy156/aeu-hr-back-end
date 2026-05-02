<?php

use App\Models\User;
use App\Services\UserPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('it returns effective roles and permissions for a user', function () {
    $employeeRole = Role::findOrCreate('employee', 'web');
    $employeeRole->givePermissionTo([
        Permission::findOrCreate('attendance.clock_in', 'web'),
        Permission::findOrCreate('leaves.create', 'web'),
    ]);
    Permission::findOrCreate('payslips.view_own', 'web');

    $user = User::factory()->create();
    $user->assignRole($employeeRole);
    $user->givePermissionTo('payslips.view_own');

    $service = app(UserPermissionService::class);

    expect($service->getRoleNames($user))->toBe(['employee']);
    expect($service->getPermissionNames($user))->toBe([
        'attendance.clock_in',
        'leaves.create',
        'payslips.view_own',
    ]);
    expect($service->getGroupedPermissions($user))->toBe([
        'attendance' => ['attendance.clock_in'],
        'leaves' => ['leaves.create'],
        'payslips' => ['payslips.view_own'],
    ]);
    expect($service->getPermissionSummary($user))->toBe([
        'roles' => ['employee'],
        'permissions' => [
            'attendance.clock_in',
            'leaves.create',
            'payslips.view_own',
        ],
        'grouped_permissions' => [
            'attendance' => ['attendance.clock_in'],
            'leaves' => ['leaves.create'],
            'payslips' => ['payslips.view_own'],
        ],
    ]);
});
