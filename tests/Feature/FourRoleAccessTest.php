<?php

use App\Enums\JobLevel;
use App\Models\Announcement;
use App\Models\AnnouncementCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollBatch;
use App\Models\PayrollItem;
use App\Models\Position;
use App\Models\User;
use App\Services\JobLevelRoleService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('role migration converts retired roles including deleted users and preserves valid roles', function () {
    foreach (['gm', 'head', 'manager'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    $gm = User::factory()->create()->assignRole('gm');
    $head = User::factory()->create()->assignRole('head');
    $head->delete();
    $manager = User::factory()->create()->assignRole('manager');
    $hr = User::factory()->create()->assignRole(['hr', 'head']);
    $ceo = User::factory()->create()->assignRole(['ceo', 'gm']);
    $admin = User::factory()->create()->assignRole(['admin', 'manager']);
    $password = $gm->password;

    $migration = require database_path('migrations/2026_10_20_000002_consolidate_access_roles.php');
    $migration->up();
    $migration->up();

    expect(Role::query()->orderBy('name')->pluck('name')->all())->toBe(['admin', 'ceo', 'employee', 'hr'])
        ->and($gm->fresh()->getRoleNames()->all())->toBe(['employee'])
        ->and($manager->fresh()->getRoleNames()->all())->toBe(['employee'])
        ->and(User::withTrashed()->find($head->id)->getRoleNames()->all())->toBe(['employee'])
        ->and($hr->fresh()->getRoleNames()->all())->toBe(['hr'])
        ->and($ceo->fresh()->getRoleNames()->all())->toBe(['ceo'])
        ->and($admin->fresh()->getRoleNames()->all())->toBe(['admin'])
        ->and($gm->fresh()->password)->toBe($password)
        ->and(User::withTrashed()->count())->toBe(6);
});

test('retired roles are unavailable and rejected even if stale role rows exist', function (string $role) {
    Role::findOrCreate($role, 'web');
    $admin = User::factory()->create()->assignRole('admin');
    $employee = User::factory()->create()->assignRole('employee');
    Sanctum::actingAs($admin);

    $roles = $this->getJson('/api/roles')->assertSuccessful()->assertJsonCount(4, 'data')->json('data');
    expect(array_column($roles, 'name'))->toBe(['admin', 'ceo', 'employee', 'hr']);

    $this->putJson("/api/users/{$employee->id}/roles", ['roles' => [$role]])
        ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    $this->putJson("/api/users/{$employee->id}", [
        'name' => $employee->name, 'email' => $employee->email, 'status' => 'active', 'roles' => [$role],
    ])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    $this->postJson('/api/users', [
        'name' => 'Invalid Role', 'email' => 'invalid.role@example.com', 'password' => 'SecurePassword123!',
        'status' => 'active', 'roles' => [$role],
    ])->assertUnprocessable()->assertJsonValidationErrors('roles.0');

    expect($employee->fresh()->getRoleNames()->all())->toBe(['employee']);
})->with(['gm', 'head', 'manager']);

test('managerial job titles retain employee access without payroll or peer payslip access', function (JobLevel $level) {
    $department = Department::query()->create(['name' => 'Commercial', 'status' => 'active']);
    $position = Position::query()->create([
        'name' => 'Leadership position', 'department_id' => $department->id,
        'job_level' => $level, 'status' => 'active',
    ]);
    $user = User::factory()->create()->assignRole('employee');
    $employee = Employee::query()->create([
        'user_id' => $user->id, 'employee_id' => 'FOUR-001', 'full_name' => $user->name,
        'department_id' => $department->id, 'position_id' => $position->id,
        'join_date' => '2026-01-01', 'base_salary' => 1000, 'employment_status' => 'full-time',
    ]);
    app(JobLevelRoleService::class)->syncForEmployee($employee);
    $peer = Employee::query()->create([
        'user_id' => User::factory()->create()->assignRole('employee')->id,
        'employee_id' => 'FOUR-002', 'full_name' => 'Peer', 'department_id' => $department->id,
        'join_date' => '2026-01-01', 'base_salary' => 1000, 'employment_status' => 'full-time',
    ]);
    $batch = PayrollBatch::query()->create(['month' => 5, 'year' => 2026, 'status' => 'approved']);
    $items = collect([$employee, $peer])->map(fn ($owner) => PayrollItem::query()->create([
        'payroll_batch_id' => $batch->id, 'employee_id' => $owner->id, 'base_salary' => 1000,
        'daily_rate' => 40, 'working_days' => 22, 'present_days' => 22,
        'gross_salary' => 1000, 'taxable_salary' => 1000, 'net_salary' => 1000, 'status' => 'locked',
    ]));

    Sanctum::actingAs($user);
    $this->getJson('/api/payrolls')->assertForbidden();
    $this->getJson('/api/reports/payroll?report_type=employee_list&month=5&year=2026')->assertForbidden();
    $this->getJson('/api/payslips')->assertSuccessful()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $items[0]->id);
    $this->getJson("/api/payslips/{$items[1]->id}")->assertForbidden();
    expect($user->fresh()->getRoleNames()->all())->toBe(['employee'])
        ->and($user->fresh()->hasPermissionTo('announcements.create'))->toBeFalse();
})->with([JobLevel::Manager, JobLevel::Head, JobLevel::Gm]);

test('role migration retires automatic head grants while preserving unrelated direct grants', function () {
    Role::findOrCreate('head', 'web');
    $department = Department::query()->create(['name' => 'HR & Admin', 'status' => 'active']);
    $position = Position::query()->create([
        'name' => 'Head of HR', 'department_id' => $department->id, 'job_level' => JobLevel::Head, 'status' => 'active',
    ]);
    $user = User::factory()->create()->assignRole('employee');
    Employee::query()->create([
        'user_id' => $user->id, 'employee_id' => 'LEGACY-001', 'full_name' => $user->name,
        'department_id' => $department->id, 'position_id' => $position->id,
        'join_date' => '2026-01-01', 'base_salary' => 1000, 'employment_status' => 'full-time',
    ]);
    $user->givePermissionTo(['announcements.create', 'payrolls.generate', 'leaves.approve_hr', 'employees.search']);
    $this->seed(RoleSeeder::class);

    expect($user->fresh()->hasPermissionTo('payrolls.generate'))->toBeFalse()
        ->and($user->fresh()->hasPermissionTo('leaves.approve_hr'))->toBeFalse()
        ->and($user->fresh()->hasPermissionTo('announcements.create'))->toBeFalse()
        ->and($user->fresh()->hasPermissionTo('employees.search'))->toBeTrue();
});

test('changing job level preserves explicitly assigned admin HR and CEO access', function (string $role) {
    $user = User::factory()->create()->assignRole($role);
    app(JobLevelRoleService::class)->syncForUser($user, JobLevel::Manager);
    expect($user->fresh()->getRoleNames()->all())->toBe([$role]);
})->with(['admin', 'hr', 'ceo']);

test('retiring a role preserves announcement recipients without broadcasting to all employees', function () {
    $role = Role::findOrCreate('gm', 'web');
    $user = User::factory()->create()->assignRole($role);
    $employee = Employee::query()->create([
        'user_id' => $user->id, 'employee_id' => 'ANN-001', 'full_name' => $user->name,
        'join_date' => '2026-01-01', 'base_salary' => 1000, 'employment_status' => 'full-time',
    ]);
    $category = AnnouncementCategory::query()->create(['name' => 'General', 'status' => 'active']);
    $announcement = Announcement::query()->create([
        'category_id' => $category->id, 'title' => 'Leadership notice', 'content' => 'Private notice',
        'status' => 'published', 'priority' => 'normal', 'created_by' => $user->id,
    ]);
    $announcement->targets()->create(['target_type' => 'role', 'target_id' => $role->id]);
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect($announcement->targets()->count())->toBe(1)
        ->and($announcement->targets()->sole()->target_type)->toBe('employee')
        ->and($announcement->targets()->sole()->target_id)->toBe($employee->id);
    $user->refresh();
    Sanctum::actingAs($user);
    $this->getJson("/api/announcements/{$announcement->id}")->assertSuccessful();
    $peer = User::factory()->create()->assignRole('employee');
    Employee::query()->create([
        'user_id' => $peer->id, 'employee_id' => 'ANN-002', 'full_name' => $peer->name,
        'join_date' => '2026-01-01', 'base_salary' => 1000, 'employment_status' => 'full-time',
    ]);
    Sanctum::actingAs($peer);
    $this->getJson("/api/announcements/{$announcement->id}")->assertForbidden();
});
