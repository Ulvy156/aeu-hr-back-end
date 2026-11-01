<?php

namespace App\Support;

use App\Enums\JobLevel;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Validator;

class LineManagerEligibility
{
    public const LEVEL_MESSAGE = 'The manager must have a job level of Manager or above.';

    public const DEPARTMENT_MESSAGE = 'The manager must belong to the same department. Only GM and CEO can manage other departments.';

    public static function message(?int $managerId, ?int $departmentId): ?string
    {
        if ($managerId === null) {
            return null;
        }

        $manager = Employee::query()->with('position')->find($managerId);

        if (! $manager) {
            return null;
        }

        $level = $manager->position?->job_level;

        if (! $level instanceof JobLevel || ! $level->canBeLineManager()) {
            return self::LEVEL_MESSAGE;
        }

        if ($level->canManageAnyDepartment()) {
            return null;
        }

        if ($departmentId === null || (int) $manager->department_id !== $departmentId) {
            return self::DEPARTMENT_MESSAGE;
        }

        return null;
    }

    public static function addError(Validator $validator, ?int $managerId, ?int $departmentId, string $attribute = 'manager_id'): void
    {
        $message = self::message($managerId, $departmentId);

        if ($message !== null) {
            $validator->errors()->add($attribute, $message);
        }
    }

    public static function constrain(Builder $query, ?int $departmentId): void
    {
        $query
            ->whereHas('position', function (Builder $position): void {
                $position->whereIn('job_level', JobLevel::lineManagerValues());
            })
            ->where(function (Builder $eligible) use ($departmentId): void {
                $eligible->whereHas('position', function (Builder $position): void {
                    $position->whereIn('job_level', JobLevel::crossDepartmentLineManagerValues());
                });

                if ($departmentId !== null) {
                    $eligible->orWhere($eligible->qualifyColumn('department_id'), $departmentId);
                }
            });
    }
}
