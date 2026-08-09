<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case FullTime = 'full-time';
    case Probation = 'probation';
    case Intern = 'intern';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
}
