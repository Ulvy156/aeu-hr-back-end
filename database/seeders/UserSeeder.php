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
        $executiveDept = Department::where('name', 'Executive')->first();
        $accountingDept = Department::where('name', 'Accounting')->first();
        $marketingDept = Department::where('name', 'Marketing')->first();
        $commercialDept = Department::where('name', 'Commercial')->first();
        $hrAdminDept = Department::where('name', 'HR & Admin')->first();

        $generalManagerPos = Position::where('name', 'General Manager')->first();
        $accountingOfficerPos = Position::where('name', 'Accounting & Stock Control Officer')->first();
        $digitalMarketingSupervisorPos = Position::where('name', 'Digital Marketing Supervisor')->first();
        $graphicDesignerPos = Position::where('name', 'Graphic Designer')->first();
        $seniorSalesSupervisorPos = Position::where('name', 'Senior Sales Supervisor')->first();
        $salesSupervisorPos = Position::where('name', 'Sales Supervisor')->first();
        $seniorSalesExecutivePos = Position::where('name', 'Senior Sales Executive')->first();
        $salesExecutivePos = Position::where('name', 'Sales Executive')->first();
        $hrManagerPos = Position::where('name', 'HR Manager')->where('department_id', $hrAdminDept?->id)->first();

        // Romanized from Khmer-script source names — best-effort transliteration, verify against official records.
        $users = [
            // ── General Manager ─────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Sim Sea',
                    'email' => 'sim.sea@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'ceo',
                'employee' => [
                    'employee_id' => 'EMP-0001',
                    'full_name' => 'Sim Sea',
                    'gender' => 'male',
                    'phone_number' => '0882207078',
                    'department_id' => $executiveDept?->id,
                    'position_id' => $generalManagerPos?->id,
                    'manager_employee_id' => null,
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Accounting & Stock Control Officer ──────────────────────────
            [
                'user' => [
                    'name' => 'Lach Sreytea',
                    'email' => 'lach.sreytea@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0002',
                    'full_name' => 'Lach Sreytea',
                    'gender' => 'female',
                    'phone_number' => '070363285',
                    'department_id' => $accountingDept?->id,
                    'position_id' => $accountingOfficerPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Digital Marketing Supervisor ─────────────────────────────────
            [
                'user' => [
                    'name' => 'Roth Nearath',
                    'email' => 'roth.nearath@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0003',
                    'full_name' => 'Roth Nearath',
                    'gender' => 'male',
                    'phone_number' => '0965328849',
                    'department_id' => $marketingDept?->id,
                    'position_id' => $digitalMarketingSupervisorPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Graphic Designer ──────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Ith Dava',
                    'email' => 'ith.dava@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0004',
                    'full_name' => 'Ith Dava',
                    'gender' => 'male',
                    'phone_number' => '099799230',
                    'department_id' => $marketingDept?->id,
                    'position_id' => $graphicDesignerPos?->id,
                    'manager_employee_id' => 'EMP-0003',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Senior Sales Supervisor ──────────────────────────────────────
            [
                'user' => [
                    'name' => 'Chorn Chan',
                    'email' => 'chorn.chan@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0005',
                    'full_name' => 'Chorn Chan',
                    'gender' => 'male',
                    'phone_number' => '010682982',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $seniorSalesSupervisorPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Sales Supervisor ──────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Chea Samprous',
                    'email' => 'chea.samprous@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0006',
                    'full_name' => 'Chea Samprous',
                    'gender' => 'male',
                    'phone_number' => '081279872',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesSupervisorPos?->id,
                    'manager_employee_id' => 'EMP-0005',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Sales Executive ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Tan Da',
                    'email' => 'tan.da@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0007',
                    'full_name' => 'Tan Da',
                    'gender' => 'male',
                    'phone_number' => '0966929332',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesExecutivePos?->id,
                    'manager_employee_id' => 'EMP-0006',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Sales Executive ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Soung Vatha',
                    'email' => 'soung.vatha@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0008',
                    'full_name' => 'Soung Vatha',
                    'gender' => 'male',
                    'phone_number' => '0889137024',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesExecutivePos?->id,
                    'manager_employee_id' => 'EMP-0006',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Senior Sales Executive ──────────────────────────────────────
            [
                'user' => [
                    'name' => 'Him Kimsreng',
                    'email' => 'him.kimsreng@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0009',
                    'full_name' => 'Him Kimsreng',
                    'gender' => 'male',
                    'phone_number' => '0968381084',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $seniorSalesExecutivePos?->id,
                    'manager_employee_id' => 'EMP-0005',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Sales Executive ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Chorn Kanna',
                    'email' => 'chorn.kanna@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0010',
                    'full_name' => 'Chorn Kanna',
                    'gender' => 'male',
                    'phone_number' => '0702923046',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesExecutivePos?->id,
                    'manager_employee_id' => 'EMP-0009',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Sales Executive ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Van Uttam',
                    'email' => 'van.uttam@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0011',
                    'full_name' => 'Van Uttam',
                    'gender' => 'male',
                    'phone_number' => '0967661004',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesExecutivePos?->id,
                    'manager_employee_id' => 'EMP-0009',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Sales Executive (no phone number in source) ──────────────────
            [
                'user' => [
                    'name' => 'Teng Kimhan',
                    'email' => 'teng.kimhan@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0012',
                    'full_name' => 'Teng Kimhan',
                    'gender' => 'male',
                    'phone_number' => null,
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesExecutivePos?->id,
                    'manager_employee_id' => 'EMP-0009',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Sales Executive ───────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'Chum Sokunthary',
                    'email' => 'chum.sokunthary@example.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'employee',
                'employee' => [
                    'employee_id' => 'EMP-0013',
                    'full_name' => 'Chum Sokunthary',
                    'gender' => 'male',
                    'phone_number' => '077656838',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesExecutivePos?->id,
                    'manager_employee_id' => 'EMP-0009',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── HR Manager ────────────────────────────────────────────────────
            [
                'user' => [
                    'name' => 'HR',
                    'email' => 'hr@gmail.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'hr',
                'employee' => [
                    'employee_id' => 'EMP-0014',
                    'full_name' => 'HR',
                    'gender' => null,
                    'phone_number' => null,
                    'department_id' => $hrAdminDept?->id,
                    'position_id' => $hrManagerPos?->id,
                    'manager_employee_id' => 'EMP-0001',
                    'join_date' => now()->toDateString(),
                    'base_salary' => 0.00,
                    'employment_status' => EmploymentStatus::FullTime->value,
                ],
            ],

            // ── Admin (system account, no employee record) ───────────────────
            [
                'user' => [
                    'name' => 'admin',
                    'email' => 'admin@gmail.com',
                    'status' => Status::Active->value,
                ],
                'role' => 'admin',
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
