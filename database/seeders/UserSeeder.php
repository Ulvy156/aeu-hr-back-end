<?php

namespace Database\Seeders;

use App\Enums\EmploymentStatus;
use App\Enums\Status;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $mgmtDept = Department::where('name', 'Management')->first();
        $hrDept = Department::where('name', 'Human Resources')->first();
        $finDept = Department::where('name', 'Finance & Accounting')->first();
        $opsDept = Department::where('name', 'Operations')->first();
        $adminDept = Department::where('name', 'Administration')->first();
        $mktDept = Department::where('name', 'Marketing & Communications')->first();

        $ceoPos = Position::where('name', 'Chief Executive Officer')->first();
        $deputyPos = Position::where('name', 'Deputy Director')->first();
        $hrManagerPos = Position::where('name', 'HR Manager')->first();
        $hrOfficerPos = Position::where('name', 'HR Officer')->first();
        $finManagerPos = Position::where('name', 'Finance Manager')->first();
        $accountantPos = Position::where('name', 'Accountant')->first();
        $opsManagerPos = Position::where('name', 'Operations Manager')->first();
        $opsCoordPos = Position::where('name', 'Operations Coordinator')->first();
        $fieldOfficerPos = Position::where('name', 'Field Officer')->first();
        $adminManagerPos = Position::where('name', 'Admin Manager')->first();
        $adminOfficerPos = Position::where('name', 'Admin Officer')->first();
        $mktManagerPos = Position::where('name', 'Marketing Manager')->first();
        $mktOfficerPos = Position::where('name', 'Marketing Officer')->first();

        $users = [
            // ── CEO ─────────────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'CEO User',
                    'email' => 'ceo@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'ceo',
                'employee' => [
                    'employee_id' => 'EMP-0001',
                    'full_name' => 'CEO User',
                    'gender' => 'male',
                    'date_of_birth' => '1975-03-15',
                    'phone_number' => '+855-12-000-001',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $mgmtDept?->id,
                    'position_id' => $ceoPos?->id,
                    'manager_employee_id' => null,
                    'join_date' => '2018-01-01',
                    'base_salary' => 8000.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Deputy Director (Admin) ─────────────────────────────────────
            [
                'user' => [
                    'name' => 'Deputy Director User',
                    'email' => 'deputy-director@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'admin',
                'employee' => [
                    'employee_id' => 'EMP-0002',
                    'full_name' => 'Deputy Director User',
                    'gender' => 'female',
                    'date_of_birth' => '1980-08-20',
                    'phone_number' => '+855-12-000-002',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $mgmtDept?->id,
                    'position_id' => $deputyPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => '2018-06-01',
                    'base_salary' => 6000.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── HR Manager ──────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'HR Manager User',
                    'email' => 'hr-manager@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'hr',
                'employee' => [
                    'employee_id' => 'EMP-0003',
                    'full_name' => 'HR Manager User',
                    'gender' => 'female',
                    'date_of_birth' => '1985-07-22',
                    'phone_number' => '+855-12-000-003',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $hrDept?->id,
                    'position_id' => $hrManagerPos?->id,
                    'manager_employee_id' => 'EMP-0002',
                    'join_date' => '2019-06-01',
                    'base_salary' => 4500.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── HR Officer ──────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'HR Officer User',
                    'email' => 'hr-officer@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0004',
                    'full_name' => 'HR Officer User',
                    'gender' => 'male',
                    'date_of_birth' => '1992-03-10',
                    'phone_number' => '+855-12-000-004',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $hrDept?->id,
                    'position_id' => $hrOfficerPos?->id,
                    'manager_employee_id' => 'EMP-0003',
                    'join_date' => '2021-02-01',
                    'base_salary' => 2600.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Finance Manager ─────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Finance Manager User',
                    'email' => 'finance-manager@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0005',
                    'full_name' => 'Finance Manager User',
                    'gender' => 'male',
                    'date_of_birth' => '1982-12-08',
                    'phone_number' => '+855-12-000-005',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $finDept?->id,
                    'position_id' => $finManagerPos?->id,
                    'manager_employee_id' => 'EMP-0002',
                    'join_date' => '2019-02-01',
                    'base_salary' => 4000.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Accountant ──────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Accountant User',
                    'email' => 'accountant@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0006',
                    'full_name' => 'Accountant User',
                    'gender' => 'female',
                    'date_of_birth' => '1990-04-10',
                    'phone_number' => '+855-12-000-006',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $finDept?->id,
                    'position_id' => $accountantPos?->id,
                    'manager_employee_id' => 'EMP-0005',
                    'join_date' => '2021-03-01',
                    'base_salary' => 2800.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Operations Manager ──────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Operations Manager User',
                    'email' => 'ops-manager@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0007',
                    'full_name' => 'Operations Manager User',
                    'gender' => 'male',
                    'date_of_birth' => '1984-05-14',
                    'phone_number' => '+855-12-000-007',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $opsDept?->id,
                    'position_id' => $opsManagerPos?->id,
                    'manager_employee_id' => 'EMP-0002',
                    'join_date' => '2019-09-01',
                    'base_salary' => 3500.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Operations Coordinator ──────────────────────────────────────
            [
                'user' => [
                    'name' => 'Operations Coordinator User',
                    'email' => 'ops-coordinator@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0008',
                    'full_name' => 'Operations Coordinator User',
                    'gender' => 'female',
                    'date_of_birth' => '1993-09-05',
                    'phone_number' => '+855-12-000-008',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $opsDept?->id,
                    'position_id' => $opsCoordPos?->id,
                    'manager_employee_id' => 'EMP-0007',
                    'join_date' => '2022-01-10',
                    'base_salary' => 2500.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Field Officer ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Field Officer User',
                    'email' => 'field-officer@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0009',
                    'full_name' => 'Field Officer User',
                    'gender' => 'male',
                    'date_of_birth' => '1995-02-18',
                    'phone_number' => '+855-12-000-009',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $opsDept?->id,
                    'position_id' => $fieldOfficerPos?->id,
                    'manager_employee_id' => 'EMP-0007',
                    'join_date' => '2023-04-01',
                    'base_salary' => 2200.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Admin Manager ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Admin Manager User',
                    'email' => 'admin-manager@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0010',
                    'full_name' => 'Admin Manager User',
                    'gender' => 'female',
                    'date_of_birth' => '1986-11-30',
                    'phone_number' => '+855-12-000-010',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $adminDept?->id,
                    'position_id' => $adminManagerPos?->id,
                    'manager_employee_id' => 'EMP-0002',
                    'join_date' => '2020-03-01',
                    'base_salary' => 3200.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Admin Officer ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Admin Officer User',
                    'email' => 'admin-officer@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0011',
                    'full_name' => 'Admin Officer User',
                    'gender' => 'male',
                    'date_of_birth' => '1994-06-25',
                    'phone_number' => '+855-12-000-011',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $adminDept?->id,
                    'position_id' => $adminOfficerPos?->id,
                    'manager_employee_id' => 'EMP-0010',
                    'join_date' => '2022-07-01',
                    'base_salary' => 2000.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Marketing Manager ───────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Marketing Manager User',
                    'email' => 'marketing-manager@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0012',
                    'full_name' => 'Marketing Manager User',
                    'gender' => 'female',
                    'date_of_birth' => '1988-01-15',
                    'phone_number' => '+855-12-000-012',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $mktDept?->id,
                    'position_id' => $mktManagerPos?->id,
                    'manager_employee_id' => 'EMP-0002',
                    'join_date' => '2020-08-15',
                    'base_salary' => 3500.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Marketing Officer ───────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Marketing Officer User',
                    'email' => 'marketing-officer@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0013',
                    'full_name' => 'Marketing Officer User',
                    'gender' => 'male',
                    'date_of_birth' => '1996-10-20',
                    'phone_number' => '+855-12-000-013',
                    'address' => 'Phnom Penh, Cambodia',
                    'department_id' => $mktDept?->id,
                    'position_id' => $mktOfficerPos?->id,
                    'manager_employee_id' => 'EMP-0012',
                    'join_date' => '2023-01-15',
                    'base_salary' => 1800.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],
        ];

        $employeesByCode = [];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['user']['email']],
                array_merge($data['user'], ['password' => 'password'])
            );

            if ($user->roles->isEmpty()) {
                $user->assignRole($data['role']);
            }

            if (isset($data['employee'])) {
                $employeeData = $data['employee'];
                $managerEmployeeId = $employeeData['manager_employee_id'];
                unset($employeeData['manager_employee_id']);

                $employee = Employee::firstOrCreate(
                    ['user_id' => $user->id],
                    $employeeData
                );

                $employeesByCode[$employeeData['employee_id']] = [
                    'employee' => $employee,
                    'manager_employee_id' => $managerEmployeeId,
                ];
            }
        }

        foreach ($employeesByCode as $entry) {
            $managerCode = $entry['manager_employee_id'];

            if ($managerCode === null) {
                continue;
            }

            $manager = $employeesByCode[$managerCode]['employee'] ?? null;

            if ($manager && $entry['employee']->manager_id !== $manager->id) {
                $entry['employee']->update(['manager_id' => $manager->id]);
            }
        }
    }
}
