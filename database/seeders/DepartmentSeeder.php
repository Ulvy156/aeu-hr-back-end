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
            'Manager',
            'Accounting',
            'Marketing',
            'Commercial',
            'HR & Admin',
        ];

        foreach ($departments as $name) {
            Department::updateOrCreate(['name' => $name], ['status' => Status::Active->value]);
        }
    }
}
