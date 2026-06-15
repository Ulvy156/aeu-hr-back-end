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
        $finDept = Department::where('name', 'Finance')->first();
        $engDept = Department::where('name', 'Information Technology')->first();
        $opsDept = Department::where('name', 'Operations')->first();

        $ceoPos = Position::where('name', 'Chief Executive Officer')->first();
        $hrManagerPos = Position::where('name', 'HR Manager')->first();
        $hrOfficerPos = Position::where('name', 'HR Officer')->first();
        $finManagerPos = Position::where('name', 'Finance Manager')->first();
        $accountantPos = Position::where('name', 'Accountant')->first();
        $techLeadPos = Position::where('name', 'Tech Lead')->first();
        $seniorSwPos = Position::where('name', 'Senior Software Engineer')->first();
        $swEngineerPos = Position::where('name', 'Software Engineer')->first();
        $opsManagerPos = Position::where('name', 'Operations Manager')->first();
        $opsOfficerPos = Position::where('name', 'Operations Officer')->first();

        $users = [
            // ── CEO ──────────────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'John Smith',
                    'email' => 'ceo@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'ceo',
                'employee' => [
                    'employee_id' => 'EMP-0001',
                    'full_name' => 'John Smith',
                    'gender' => 'male',
                    'date_of_birth' => '1975-03-15',
                    'phone_number' => '+1-555-0001',
                    'address' => '1 Executive Drive, Capital City',
                    'department_id' => $mgmtDept?->id,
                    'position_id' => $ceoPos?->id,
                    'manager_employee_id' => null,
                    'join_date' => '2018-01-01',
                    'base_salary' => 8000.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── HR ───────────────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Sarah Connor',
                    'email' => 'hr@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'hr',
                'employee' => [
                    'employee_id' => 'EMP-0002',
                    'full_name' => 'Sarah Connor',
                    'gender' => 'female',
                    'date_of_birth' => '1985-07-22',
                    'phone_number' => '+1-555-0002',
                    'address' => '2 HR Lane, Business District',
                    'department_id' => $hrDept?->id,
                    'position_id' => $hrManagerPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => '2019-06-01',
                    'base_salary' => 4500.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Employees ────────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Alice Johnson',
                    'email' => 'alice.johnson@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0003',
                    'full_name' => 'Alice Johnson',
                    'gender' => 'female',
                    'date_of_birth' => '1990-04-10',
                    'phone_number' => '+1-555-0003',
                    'address' => '3 Finance St, Commerce Zone',
                    'department_id' => $finDept?->id,
                    'position_id' => $accountantPos?->id,
                    'manager_employee_id' => 'EMP-0008',
                    'join_date' => '2021-03-01',
                    'base_salary' => 2800.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],
            [
                'user' => [
                    'name' => 'Bob Martinez',
                    'email' => 'bob.martinez@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0004',
                    'full_name' => 'Bob Martinez',
                    'gender' => 'male',
                    'date_of_birth' => '1988-11-30',
                    'phone_number' => '+1-555-0004',
                    'address' => '4 Tech Park, Innovation District',
                    'department_id' => $engDept?->id,
                    'position_id' => $seniorSwPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => '2020-08-15',
                    'base_salary' => 3500.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],
            [
                'user' => [
                    'name' => 'Clara Davis',
                    'email' => 'clara.davis@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0005',
                    'full_name' => 'Clara Davis',
                    'gender' => 'female',
                    'date_of_birth' => '1993-09-05',
                    'phone_number' => '+1-555-0005',
                    'address' => '5 Ops Road, Industrial Zone',
                    'department_id' => $opsDept?->id,
                    'position_id' => $opsOfficerPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => '2022-01-10',
                    'base_salary' => 2500.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],
            [
                'user' => [
                    'name' => 'David Lee',
                    'email' => 'david.lee@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0006',
                    'full_name' => 'David Lee',
                    'gender' => 'male',
                    'date_of_birth' => '1995-02-18',
                    'phone_number' => '+1-555-0006',
                    'address' => '6 Dev Street, Tech Quarter',
                    'department_id' => $engDept?->id,
                    'position_id' => $swEngineerPos?->id,
                    'manager_employee_id' => 'EMP-0004',
                    'join_date' => '2023-04-01',
                    'base_salary' => 2200.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],
            [
                'user' => [
                    'name' => 'Eva Williams',
                    'email' => 'eva.williams@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0007',
                    'full_name' => 'Eva Williams',
                    'gender' => 'female',
                    'date_of_birth' => '1987-06-25',
                    'phone_number' => '+1-555-0007',
                    'address' => '7 HR Ave, Central Area',
                    'department_id' => $hrDept?->id,
                    'position_id' => $hrOfficerPos?->id,
                    'manager_employee_id' => 'EMP-0002',
                    'join_date' => '2020-11-01',
                    'base_salary' => 2600.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],
            [
                'user' => [
                    'name' => 'Frank Wilson',
                    'email' => 'frank.wilson@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0008',
                    'full_name' => 'Frank Wilson',
                    'gender' => 'male',
                    'date_of_birth' => '1982-12-08',
                    'phone_number' => '+1-555-0008',
                    'address' => '8 Finance Blvd, Commerce Zone',
                    'department_id' => $finDept?->id,
                    'position_id' => $finManagerPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => '2019-02-01',
                    'base_salary' => 4000.00,
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

            // Only assign role on first creation to avoid overwriting manual changes
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

        // Second pass: resolve manager_employee_id (e.g. 'EMP-0008') to the
        // manager's actual primary key, now that every employee row exists.
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
