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
}
