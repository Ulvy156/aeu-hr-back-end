<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case FullTime = 'full-time';
    case Probation = 'probation';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
}
