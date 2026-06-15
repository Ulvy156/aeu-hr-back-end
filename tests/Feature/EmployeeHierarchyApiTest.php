<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function hierarchyActor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hierarchyEmployee(array $overrides = []): Employee
{
    static $counter = 0;
    $counter++;

    $linkedUser = User::factory()->create([
        'name' => "Hierarchy Employee {$counter}",
        'email' => "hierarchy.employee{$counter}@example.com",
        'status' => 'active',
    ]);
    $linkedUser->assignRole('employee');

    return Employee::query()->create(array_merge([
        'user_id' => $linkedUser->id,
        'employee_id' => 'EMP-H'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'full_name' => "Hierarchy Employee {$counter}",
        'join_date' => '2026-01-01',
        'base_salary' => '1000.00',
        'employment_status' => 'full-time',
    ], $overrides));
}

test('hierarchy endpoint returns the nested org chart for any authenticated user', function () {
    [$department, $position] = (function () {
        $department = Department::query()->create(['name' => 'Executive', 'status' => 'active']);
        $position = Position::query()->create(['name' => 'CEO', 'department_id' => $department->id, 'status' => 'active']);

        return [$department, $position];
    })();

    $ceo = hierarchyEmployee([
        'full_name' => 'Top CEO',
        'department_id' => $department->id,
        'position_id' => $position->id,
    ]);
    $manager = hierarchyEmployee(['full_name' => 'Middle Manager', 'manager_id' => $ceo->id]);
    $staffA = hierarchyEmployee(['full_name' => 'Staff A', 'manager_id' => $manager->id]);
    $staffB = hierarchyEmployee(['full_name' => 'Staff B', 'manager_id' => $manager->id]);

    Sanctum::actingAs(hierarchyActor('employee'));

    $response = $this->getJson('/api/employees/hierarchy')->assertOk();

    $tree = $response->json('data');

    expect($tree)->toHaveCount(1);

    $root = $tree[0];

    expect($root['id'])->toBe($ceo->id)
        ->and($root['full_name'])->toBe('Top CEO')
        ->and($root)->toHaveKey('profile_photo_url')
        ->and($root['department'])->toBe(['id' => $department->id, 'name' => 'Executive'])
        ->and($root['position'])->toBe(['id' => $position->id, 'name' => 'CEO'])
        ->and($root['children'])->toHaveCount(1);

    $managerNode = $root['children'][0];

    expect($managerNode['id'])->toBe($manager->id)
        ->and($managerNode['children'])->toHaveCount(2);

    $staffIds = collect($managerNode['children'])->pluck('id')->all();

    expect($staffIds)->toEqualCanonicalizing([$staffA->id, $staffB->id]);
});

test('soft deleted employees are excluded from the hierarchy', function () {
    $manager = hierarchyEmployee(['full_name' => 'Active Manager']);
    $staff = hierarchyEmployee(['full_name' => 'Departed Staff', 'manager_id' => $manager->id]);
    $staff->delete();

    Sanctum::actingAs(hierarchyActor('admin'));

    $response = $this->getJson('/api/employees/hierarchy')->assertOk();

    $tree = $response->json('data');
    $root = collect($tree)->firstWhere('id', $manager->id);

    expect($root)->not->toBeNull()
        ->and($root['children'])->toBe([]);
});
