<?php

namespace Database\Seeders;

use App\Enums\EmploymentStatus;
use App\Enums\Status;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\JobLevelRoleService;
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

        $ceoPos = Position::where('name', 'Chief Executive Officer')->first();
        $generalManagerPos = Position::where('name', 'General Manager')->first();
        $accountStockPos = Position::where('name', 'Account & Stock')->first();
        $marketingSupervisorPos = Position::where('name', 'Marketing Supervisor')->first();
        $designPos = Position::where('name', 'Design')->first();
        $seniorSalesSupervisorPos = Position::where('name', 'Senior Sales Supervisor')->first();
        $salesSupervisorPos = Position::where('name', 'Sales Supervisor')->first();
        $salesExecutivePos = Position::where('name', 'Sales Executive')->first();
        $hrAdminPos = Position::where('name', 'HR Admin')->where('department_id', $hrAdminDept?->id)->first();

        $joinDate = now()->toDateString();
        $fullTime = EmploymentStatus::FullTime->value;

        // Occupied org-chart seats only. Vacant boxes are positions without users.
        $users = [
            [
                'user' => ['name' => 'Tum Punleu', 'email' => 'tum.punleu@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0001',
                    'full_name' => 'Tum Punleu',
                    'gender' => 'male',
                    'department_id' => $executiveDept?->id,
                    'position_id' => $ceoPos?->id,
                    'manager_email' => null,
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            [
                'user' => ['name' => 'Sim Sea', 'email' => 'sim.sea@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0002',
                    'full_name' => 'Sim Sea',
                    'gender' => 'male',
                    'department_id' => $executiveDept?->id,
                    'position_id' => $generalManagerPos?->id,
                    'manager_email' => 'tum.punleu@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            [
                'user' => ['name' => 'Thun Chan', 'email' => 'thun.chan@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0003',
                    'full_name' => 'Thun Chan',
                    'gender' => 'male',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $seniorSalesSupervisorPos?->id,
                    'manager_email' => 'sim.sea@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            ...$this->salesExecutives($commercialDept?->id, $salesExecutivePos?->id, 'thun.chan@gmail.com', $joinDate, $fullTime, [
                ['EMP-0004', 'Nhel Visal', 'nhel.visal@gmail.com'],
                ['EMP-0005', 'Chum Mab', 'chum.mab@gmail.com'],
                ['EMP-0006', 'Van Oudom', 'van.oudom@gmail.com'],
                ['EMP-0007', 'Varak Soyhah', 'varak.soyhah@gmail.com'],
                ['EMP-0008', 'Soung Rotha', 'soung.rotha@gmail.com'],
            ]),
            [
                'user' => ['name' => 'Chea Sombo', 'email' => 'chea.sombo@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0009',
                    'full_name' => 'Chea Sombo',
                    'gender' => 'male',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesSupervisorPos?->id,
                    'manager_email' => 'sim.sea@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            ...$this->salesExecutives($commercialDept?->id, $salesExecutivePos?->id, 'chea.sombo@gmail.com', $joinDate, $fullTime, [
                ['EMP-0010', 'Chum Sopeakny', 'chum.sopeakny@gmail.com'],
                ['EMP-0011', 'Meach Chay', 'meach.chay@gmail.com'],
                ['EMP-0012', 'Chuob Vireak', 'chuob.vireak@gmail.com'],
            ]),
            [
                'user' => ['name' => 'Khea Chhunly', 'email' => 'khea.chhunly@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0013',
                    'full_name' => 'Khea Chhunly',
                    'gender' => 'male',
                    'department_id' => $commercialDept?->id,
                    'position_id' => $salesSupervisorPos?->id,
                    'manager_email' => 'sim.sea@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            ...$this->salesExecutives($commercialDept?->id, $salesExecutivePos?->id, 'khea.chhunly@gmail.com', $joinDate, $fullTime, [
                ['EMP-0014', 'Vieng Rothnea', 'vieng.rothnea@gmail.com'],
                ['EMP-0015', 'Tit Phearan', 'tit.phearan@gmail.com'],
                ['EMP-0016', 'Pan Sobon', 'pan.sobon@gmail.com'],
            ]),
            [
                'user' => ['name' => 'Roth Narak', 'email' => 'roth.narak@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0017',
                    'full_name' => 'Roth Narak',
                    'gender' => 'male',
                    'department_id' => $marketingDept?->id,
                    'position_id' => $marketingSupervisorPos?->id,
                    'manager_email' => 'sim.sea@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            [
                'user' => ['name' => 'Ouk Dara', 'email' => 'ouk.dara@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0018',
                    'full_name' => 'Ouk Dara',
                    'gender' => 'male',
                    'department_id' => $marketingDept?->id,
                    'position_id' => $designPos?->id,
                    'manager_email' => 'roth.narak@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            [
                'user' => ['name' => 'Leach Sreykea', 'email' => 'leach.sreykea@gmail.com', 'status' => Status::Active->value],
                'employee' => [
                    'employee_id' => 'EMP-0019',
                    'full_name' => 'Leach Sreykea',
                    'gender' => 'female',
                    'department_id' => $accountingDept?->id,
                    'position_id' => $accountStockPos?->id,
                    'manager_email' => 'sim.sea@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            [
                'user' => ['name' => 'Kean Da', 'email' => 'hr@gmail.com', 'status' => Status::Active->value],
                'role' => 'hr',
                'employee' => [
                    'employee_id' => 'EMP-0020',
                    'full_name' => 'Kean Da',
                    'gender' => null,
                    'department_id' => $hrAdminDept?->id,
                    'position_id' => $hrAdminPos?->id,
                    'manager_email' => 'sim.sea@gmail.com',
                    'join_date' => $joinDate,
                    'base_salary' => 0.00,
                    'employment_status' => $fullTime,
                ],
            ],
            [
                'user' => ['name' => 'admin', 'email' => 'admin@gmail.com', 'status' => Status::Active->value],
                'role' => 'admin',
            ],
        ];

        $employeesByEmail = [];
        $syncRoleFor = [];
        $jobLevelRoleService = app(JobLevelRoleService::class);

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['user']['email']],
                array_merge($data['user'], ['password' => 'password'])
            );

            if (isset($data['role']) && $user->roles->isEmpty()) {
                $user->assignRole($data['role']);
            }

            if (! isset($data['employee'])) {
                continue;
            }

            $employeeData = $data['employee'];
            $managerEmail = $employeeData['manager_email'] ?? null;
            unset($employeeData['manager_email']);

            $employee = Employee::withTrashed()->where('user_id', $user->id)->first();

            if ($employee === null) {
                $employeeData['employee_id'] = $this->availableEmployeeId($employeeData['employee_id']);
                $employee = Employee::query()->create(array_merge($employeeData, ['user_id' => $user->id]));
            }

            $employeesByEmail[$user->email] = [
                'employee' => $employee,
                'manager_email' => $managerEmail,
                'just_created' => $employee->wasRecentlyCreated,
            ];

            if ($employee->wasRecentlyCreated) {
                $syncRoleFor[] = $employee;
            }
        }

        foreach ($employeesByEmail as $entry) {
            if (! $entry['just_created'] || $entry['manager_email'] === null) {
                continue;
            }

            $manager = $employeesByEmail[$entry['manager_email']]['employee'] ?? null;

            if ($manager && $entry['employee']->manager_id !== $manager->id) {
                $entry['employee']->update(['manager_id' => $manager->id]);
            }
        }

        foreach ($syncRoleFor as $employee) {
            $jobLevelRoleService->syncForEmployee($employee);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $people
     * @return array<int, array<string, mixed>>
     */
    protected function salesExecutives(?int $departmentId, ?int $positionId, string $managerEmail, string $joinDate, string $employmentStatus, array $people): array
    {
        return array_map(fn (array $person): array => [
            'user' => ['name' => $person[1], 'email' => $person[2], 'status' => Status::Active->value],
            'employee' => [
                'employee_id' => $person[0],
                'full_name' => $person[1],
                'gender' => 'male',
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'manager_email' => $managerEmail,
                'join_date' => $joinDate,
                'base_salary' => 0.00,
                'employment_status' => $employmentStatus,
            ],
        ], $people);
    }

    protected function availableEmployeeId(string $preferred): string
    {
        if (! Employee::withTrashed()->where('employee_id', $preferred)->exists()) {
            return $preferred;
        }

        $highestNumber = Employee::withTrashed()
            ->where('employee_id', 'like', 'EMP-%')
            ->pluck('employee_id')
            ->map(fn (string $employeeId) => (int) substr($employeeId, 4))
            ->max() ?? 0;

        return 'EMP-'.str_pad((string) ($highestNumber + 1), 5, '0', STR_PAD_LEFT);
    }
}
