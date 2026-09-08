<?php

namespace Database\Seeders;

use App\Enums\JobLevel;
use App\Enums\Status;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $structure = [
            'Executive' => [
                ['name' => 'Chief Executive Officer', 'job_level' => JobLevel::Ceo],
                ['name' => 'General Manager', 'job_level' => JobLevel::Gm],
            ],
            'Accounting' => [
                ['name' => 'Account & Stock', 'job_level' => JobLevel::Junior],
            ],
            'Marketing' => [
                ['name' => 'Marketing Supervisor', 'job_level' => JobLevel::Supervisor],
                ['name' => 'Design', 'job_level' => JobLevel::Junior],
                ['name' => 'Off Line', 'job_level' => JobLevel::Junior],
            ],
            'Commercial' => [
                ['name' => 'Senior Sales Supervisor', 'job_level' => JobLevel::Supervisor],
                ['name' => 'Sales Supervisor', 'job_level' => JobLevel::Supervisor],
                ['name' => 'Sales Executive', 'job_level' => JobLevel::Junior],
                ['name' => 'Sales Admin', 'job_level' => JobLevel::Junior],
            ],
            'HR & Admin' => [
                ['name' => 'HR Admin', 'job_level' => JobLevel::Junior],
            ],
        ];

        foreach ($structure as $deptName => $positions) {
            $department = Department::where('name', $deptName)->first();

            if (! $department) {
                continue;
            }

            foreach ($positions as $position) {
                Position::updateOrCreate(
                    ['name' => $position['name'], 'department_id' => $department->id],
                    [
                        'status' => Status::Active->value,
                        'job_level' => $position['job_level']->value,
                    ]
                );
            }
        }
    }
}
