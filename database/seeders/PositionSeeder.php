<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $structure = [
            'Management' => [
                'Chief Executive Officer',
                'Director',
            ],
            'Human Resources' => [
                'HR Manager',
                'HR Officer',
            ],
            'Finance' => [
                'Finance Manager',
                'Accountant',
                'Finance Officer',
            ],
            'Information Technology' => [
                'Tech Lead',
                'Senior Software Engineer',
                'Software Engineer',
            ],
            'Operations' => [
                'Operations Manager',
                'Operations Officer',
            ],
        ];

        foreach ($structure as $deptName => $positions) {
            $department = Department::where('name', $deptName)->first();

            if (! $department) {
                continue;
            }

            foreach ($positions as $positionName) {
                Position::updateOrCreate(
                    ['name' => $positionName, 'department_id' => $department->id],
                    ['status' => 'active']
                );
            }
        }
    }
}
