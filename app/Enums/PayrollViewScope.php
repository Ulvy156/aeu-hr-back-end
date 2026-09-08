<?php

namespace App\Enums;

enum PayrollViewScope: string
{
    case All = 'all';
    case Department = 'department';
    case Own = 'own';
}
