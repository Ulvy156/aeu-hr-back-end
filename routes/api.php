<?php

use App\Http\Controllers\Api\AnnouncementCategoryController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\Dashboard\AdminDashboardController;
use App\Http\Controllers\Api\Dashboard\CeoDashboardController;
use App\Http\Controllers\Api\Dashboard\EmployeeDashboardController;
use App\Http\Controllers\Api\Dashboard\HrDashboardController;
use App\Http\Controllers\Api\Dashboard\UserSummaryController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeUpgradeRequestController;
use App\Http\Controllers\Api\EmploymentHistoryController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PayslipController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicHolidayController;
use App\Http\Controllers\Api\RecruitmentCandidateController;
use App\Http\Controllers\Api\RecruitmentVacancyController;
use App\Http\Controllers\Api\Reports\AttendanceReportController;
use App\Http\Controllers\Api\Reports\LeaveReportController;
use App\Http\Controllers\Api\Reports\PayrollReportController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPermissionController;
use Illuminate\Support\Facades\Route;

// Render health check — must return 200 for the service to be marked healthy
Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::middleware('throttle:login')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
    Route::get('/leave-balances', [LeaveController::class, 'balances']);
    Route::apiResource('leaves', LeaveController::class)
        ->parameters(['leaves' => 'leave'])
        ->only(['index', 'store', 'show']);
    Route::apiResource('payrolls', PayrollController::class)
        ->parameters(['payrolls' => 'payroll'])
        ->only(['index', 'store', 'show', 'update']);
    Route::post('/payrolls/{payroll}/submit', [PayrollController::class, 'submit']);
    Route::post('/payrolls/{payroll}/approve', [PayrollController::class, 'approve']);
    Route::post('/payrolls/{payroll}/reject', [PayrollController::class, 'reject']);
    Route::apiResource('payslips', PayslipController::class)
        ->parameters(['payslips' => 'payslip'])
        ->only(['index', 'show']);
    Route::get('/payslips/{payslip}/download', [PayslipController::class, 'download']);
    Route::post('/leaves/{leave}/approve', [LeaveController::class, 'approve']);
    Route::post('/leaves/{leave}/reject', [LeaveController::class, 'reject']);
    Route::post('/leaves/{leave}/cancel', [LeaveController::class, 'cancel']);
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('/summary', [AttendanceController::class, 'summary']);
        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::post('/proxy-clock-in', [AttendanceController::class, 'proxyClockIn']);
        Route::post('/proxy-clock-out', [AttendanceController::class, 'proxyClockOut']);
        Route::put('/{attendance}/correction', [AttendanceController::class, 'correct']);
        Route::post('/mark-absent', [AttendanceController::class, 'markAbsent']);
    });
    Route::prefix('dashboard')->group(function () {
        Route::get('/employee', EmployeeDashboardController::class)
            ->middleware('permission:dashboards.employee_view');
        Route::get('/hr', HrDashboardController::class)
            ->middleware('permission:dashboards.hr_view');
        Route::get('/ceo', CeoDashboardController::class)
            ->middleware('permission:dashboards.ceo_view');
        Route::get('/admin', AdminDashboardController::class)
            ->middleware('permission:dashboards.admin_view');
        Route::get('/users-summary', UserSummaryController::class)
            ->middleware('permission:dashboards.admin_view');
    });
    Route::prefix('reports')->group(function () {
        Route::get('/payroll', [PayrollReportController::class, 'index'])
            ->middleware('permission:reports.payroll_view');
        Route::get('/payroll/export', [PayrollReportController::class, 'export'])
            ->middleware('permission:reports.payroll_export');
        Route::get('/attendance', [AttendanceReportController::class, 'index'])
            ->middleware('permission:reports.attendance_view');
        Route::get('/attendance/export', [AttendanceReportController::class, 'export'])
            ->middleware('permission:reports.attendance_export');
        Route::get('/leave', [LeaveReportController::class, 'index'])
            ->middleware('permission:reports.leave_view');
        Route::get('/leave/export', [LeaveReportController::class, 'export'])
            ->middleware('permission:reports.leave_export');
    });
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/settings/company', [CompanySettingController::class, 'show']);
    Route::put('/settings/company', [CompanySettingController::class, 'update']);
    Route::apiResource('public-holidays', PublicHolidayController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('positions', PositionController::class);
    Route::get('/employees/search', [EmployeeController::class, 'search']);
    Route::get('/employees/{employee}/employment-history', [EmploymentHistoryController::class, 'index']);
    Route::apiResource('employees', EmployeeController::class);

    Route::apiResource('employee-upgrade-requests', EmployeeUpgradeRequestController::class)
        ->parameters(['employee-upgrade-requests' => 'upgradeRequest'])
        ->only(['index', 'store', 'show']);
    Route::post('/employee-upgrade-requests/{upgradeRequest}/approve', [EmployeeUpgradeRequestController::class, 'approve']);
    Route::post('/employee-upgrade-requests/{upgradeRequest}/reject', [EmployeeUpgradeRequestController::class, 'reject']);
    Route::post('/employee-upgrade-requests/{upgradeRequest}/cancel', [EmployeeUpgradeRequestController::class, 'cancel']);

    Route::apiResource('announcement-categories', AnnouncementCategoryController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('announcements', AnnouncementController::class)
        ->only(['index', 'store', 'show', 'update']);
    Route::post('/announcements/{announcement}/submit', [AnnouncementController::class, 'submit']);
    Route::post('/announcements/{announcement}/cancel-submission', [AnnouncementController::class, 'cancelSubmission']);
    Route::post('/announcements/{announcement}/approve', [AnnouncementController::class, 'approve']);
    Route::post('/announcements/{announcement}/reject', [AnnouncementController::class, 'reject']);
    Route::post('/announcements/{announcement}/archive', [AnnouncementController::class, 'archive']);
    Route::post('/announcements/{announcement}/read', [AnnouncementController::class, 'read']);

    Route::prefix('recruitment')->group(function () {
        Route::apiResource('vacancies', RecruitmentVacancyController::class)
            ->parameters(['vacancies' => 'vacancy'])
            ->only(['index', 'store', 'show', 'update']);
        Route::post('/vacancies/{vacancy}/close', [RecruitmentVacancyController::class, 'close']);

        Route::apiResource('candidates', RecruitmentCandidateController::class)
            ->parameters(['candidates' => 'candidate'])
            ->only(['index', 'store', 'show', 'update']);
        Route::post('/candidates/{candidate}/status', [RecruitmentCandidateController::class, 'updateStatus']);
    });

    Route::middleware('role:admin|hr')->group(function () {
        Route::get('/users/search', [UserController::class, 'search']);
        Route::apiResource('users', UserController::class)->only(['index']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class)->only(['show', 'store', 'update', 'destroy']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->middleware('permission:users.reset_password');
        Route::put('/users/{user}/roles', [RolePermissionController::class, 'syncUserRoles'])
            ->middleware('permission:users.assign_roles');
        Route::get('/users/{user}/permissions', [UserPermissionController::class, 'show'])
            ->middleware('permission:users.view');
        Route::put('/users/{user}/permissions', [UserPermissionController::class, 'sync'])
            ->middleware('permission:users.assign_permissions');
        Route::post('/users/{user}/permissions', [UserPermissionController::class, 'store'])
            ->middleware('permission:users.assign_permissions');
        Route::delete('/users/{user}/permissions', [UserPermissionController::class, 'destroy'])
            ->middleware('permission:users.assign_permissions');
        Route::get('/roles', [RolePermissionController::class, 'roles'])
            ->middleware('permission:roles_permissions.roles_view');
        Route::get('/permissions', [RolePermissionController::class, 'permissions'])
            ->middleware('permission:roles_permissions.permissions_view');
    });
});
