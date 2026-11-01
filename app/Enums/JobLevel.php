<?php

namespace App\Enums;

enum JobLevel: string
{
    case Junior = 'junior';
    case Senior = 'senior';
    case Supervisor = 'supervisor';
    case Manager = 'manager';
    case Head = 'head';
    case Gm = 'gm';
    case Ceo = 'ceo';

    public function canBeLineManager(): bool
    {
        return match ($this) {
            self::Manager, self::Head, self::Gm, self::Ceo => true,
            default => false,
        };
    }

    public function canManageAnyDepartment(): bool
    {
        return match ($this) {
            self::Gm, self::Ceo => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function lineManagerValues(): array
    {
        return array_values(array_map(
            fn (self $level): string => $level->value,
            array_filter(self::cases(), fn (self $level): bool => $level->canBeLineManager()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function crossDepartmentLineManagerValues(): array
    {
        return array_values(array_map(
            fn (self $level): string => $level->value,
            array_filter(self::cases(), fn (self $level): bool => $level->canManageAnyDepartment()),
        ));
    }
}
