<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'Management',
            'Human Resources',
            'Finance',
            'Information Technology',
            'Operations',
        ];

        foreach ($departments as $name) {
            Department::updateOrCreate(['name' => $name], ['status' => Status::Active->value]);
        }
    }
}
