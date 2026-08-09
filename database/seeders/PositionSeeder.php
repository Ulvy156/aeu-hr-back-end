<?php

namespace Database\Seeders;

use App\Enums\Status;
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
                'Deputy Director',
            ],
            'Human Resources' => [
                'HR Manager',
                'HR Officer',
            ],
            'Finance & Accounting' => [
                'Finance Manager',
                'Accountant',
            ],
            'Operations' => [
                'Operations Manager',
                'Operations Coordinator',
                'Field Officer',
            ],
            'Administration' => [
                'Admin Manager',
                'Admin Officer',
            ],
            'Marketing & Communications' => [
                'Marketing Manager',
                'Marketing Officer',
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
                    ['status' => Status::Active->value]
                );
            }
        }
    }
}
