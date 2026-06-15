<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Each feature's routes live in their own file under routes/api/, mirroring
| the per-module docs in .claude/api/.
|
*/

require __DIR__.'/api/auth.php';
require __DIR__.'/api/users.php';
require __DIR__.'/api/departments.php';
require __DIR__.'/api/positions.php';
require __DIR__.'/api/employees.php';
require __DIR__.'/api/attendance.php';
require __DIR__.'/api/leaves.php';
require __DIR__.'/api/payroll.php';
require __DIR__.'/api/announcements.php';
require __DIR__.'/api/recruitment.php';
require __DIR__.'/api/settings.php';
require __DIR__.'/api/dashboard.php';
require __DIR__.'/api/reports.php';
require __DIR__.'/api/audit-logs.php';
