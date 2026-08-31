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
            'Executive' => [
                'General Manager',
            ],
            'Accounting' => [
                'Accounting & Stock Control Officer',
            ],
            'Marketing' => [
                'Digital Marketing Supervisor',
                'Graphic Designer',
            ],
            'Commercial' => [
                'Senior Sales Supervisor',
                'Sales Supervisor',
                'Senior Sales Executive',
                'Sales Executive',
            ],
            'HR & Admin' => [
                'HR Manager',
                'HR Officer',
                'Admin Officer',
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
