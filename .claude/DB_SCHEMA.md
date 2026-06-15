HR MANAGEMENT SYSTEM - FINAL LEAN MVP DATABASE DIAGRAM + MYSQL SCHEMA
================================================================================
Laravel + MySQL + Sanctum + Spatie Laravel Permission
Final Lean MVP Version with Minor Final Refinements
================================================================================

PURPOSE
================================================================================
This document defines the final lean MVP database structure for the HR Management System.

This schema is designed for:
- Laravel migrations
- MySQL database
- Spatie Laravel Permission
- Simple MVP implementation
- Secure and maintainable HR workflow

Skipped for MVP:
- attendance_corrections table
- leave_balances table
- tax_brackets table
- backups table

Reason:
- attendance correction can be stored directly in attendances for MVP
- leave balance can be calculated from approved leave_requests
- tax logic can be handled in PayrollService, TaxCalculationService, or config/hr.php first
- backup actions can be tracked in audit_logs


MAIN MVP TABLES
================================================================================
1. users
2. Spatie permission tables
3. departments
4. positions
5. employees
6. company_settings
7. public_holidays
8. attendances
9. leave_requests
10. payroll_batches
11. payroll_items
12. audit_logs


OPTIONAL TABLES FOR FUTURE VERSION
================================================================================
1. attendance_corrections
2. leave_balances
3. tax_brackets
4. backups


IMPORTANT MVP DECISIONS
================================================================================

1. No role_id in users
--------------------------------------------------------------------------------
Spatie Laravel Permission handles roles and permissions using:
- roles
- permissions
- model_has_roles
- model_has_permissions
- role_has_permissions


2. Attendance correction stays inside attendances for MVP
--------------------------------------------------------------------------------
Use these columns:
- correction_reason
- corrected_by
- corrected_at

This stores the latest correction only.

If full correction history is required later, add attendance_corrections table.


3. No leave_balances table for MVP
--------------------------------------------------------------------------------
Calculate leave balance from approved leave_requests.

Example:
Annual remaining = 18 - SUM(approved annual leave total_days for current year)

Sick remaining = 7 - SUM(approved sick leave total_days for current year)

Unpaid leave has no balance limit and affects payroll.


4. No tax_brackets table for MVP
--------------------------------------------------------------------------------
Put tax logic in:
- PayrollService
- TaxCalculationService
- config/hr.php

If admin needs to update tax brackets from UI later, add tax_brackets table.


5. No backups table for MVP
--------------------------------------------------------------------------------
Track backup create/download actions in audit_logs.

If Admin needs backup list/history UI later, add backups table.


6. company_settings is single-row
--------------------------------------------------------------------------------
Enforce this in service logic:

- If company_settings row exists, update it
- If no row exists, create it
- Do not allow multiple company settings rows


TABLE RELATIONSHIP SUMMARY
================================================================================

users
- one user can have one employee profile
- one user can create audit logs
- one user can correct attendance
- one user can approve/reject leave
- one user can generate/submit/approve/reject payroll

departments
- one department can have many positions
- one department can have many employees

positions
- one position can have many employees

employees
- one employee belongs to one user
- one employee belongs to one department
- one employee belongs to one position
- one employee can have many attendances
- one employee can have many leave requests
- one employee can have many payroll items

company_settings
- single-row settings table
- stores company profile, office GPS, working hours, working days, payroll day rate

public_holidays
- stores public holidays
- used by attendance and payroll logic

attendances
- one record per employee per date
- stores clock-in/clock-out and GPS data
- stores latest correction reason for MVP

leave_requests
- stores leave requests
- supports HR + CEO dual approval

payroll_batches
- one monthly payroll run
- unique month + year
- CEO approves/rejects payroll batch

payroll_items
- employee payroll detail inside payroll batch
- used to generate payslip PDF on demand

audit_logs
- tracks important system actions
- visible only to Admin and CEO


ASCII DATABASE DIAGRAM
================================================================================

+------------------+              +------------------+
| users            | 1          1 | employees        |
|------------------|--------------|------------------|
| id PK            |              | id PK            |
| name             |              | user_id FK       |
| email            |              | department_id FK |
| password         |              | position_id FK   |
| status           |              | employee_id      |
| email_verified_at|              | full_name        |
| remember_token   |              | gender           |
| created_at       |              | date_of_birth    |
| updated_at       |              | phone_number     |
+------------------+              | email            |
        |                         | address          |
        |                         | join_date        |
        |                         | last_working_date|
        |                         | base_salary      |
        |                         | employment_status|
        |                         | emergency_contact|
        |                         | profile_photo    |
        |                         | deleted_at       |
        |                         +------------------+
        |                                  |
        |                                  |
        |                                  N
        |                         +------------------+
        |                         | attendances      |
        |                         |------------------|
        |                         | id PK            |
        |                         | employee_id FK   |
        |                         | attendance_date  |
        |                         | clock_in_time    |
        |                         | clock_out_time   |
        |                         | clock_in_latitude|
        |                         | clock_in_longitude|
        |                         | clock_out_latitude|
        |                         | clock_out_longitude|
        |                         | status           |
        |                         | is_late          |
        |                         | correction_reason|
        |                         | corrected_by FK  |
        |                         | corrected_at     |
        |                         +------------------+
        |
        |                         +------------------+
        |                         | leave_requests   |
        |                         |------------------|
        |                         | id PK            |
        |                         | employee_id FK   |
        |                         | leave_type       |
        |                         | start_date       |
        |                         | end_date         |
        |                         | duration_type    |
        |                         | total_days       |
        |                         | reason           |
        |                         | status           |
        |                         | hr_approval_status|
        |                         | hr_approved_by FK|
        |                         | hr_approved_at   |
        |                         | ceo_approval_status|
        |                         | ceo_approved_by FK|
        |                         | ceo_approved_at  |
        |                         | rejection_reason |
        |                         | cancelled_at     |
        |                         +------------------+
        |
        |                         +------------------+
        |                         | payroll_batches  |
        |                         |------------------|
        |                         | id PK            |
        |                         | month            |
        |                         | year             |
        |                         | status           |
        |                         | generated_by FK  |
        |                         | generated_at     |
        |                         | submitted_by FK  |
        |                         | submitted_at     |
        |                         | approved_by FK   |
        |                         | approved_at      |
        |                         | rejected_by FK   |
        |                         | rejected_at      |
        |                         | rejection_reason |
        |                         +------------------+
        |                                  |
        |                                  N
        |                         +------------------+
        |                         | payroll_items    |
        |                         |------------------|
        |                         | id PK            |
        |                         | payroll_batch_id FK|
        |                         | employee_id FK   |
        |                         | base_salary      |
        |                         | daily_rate       |
        |                         | working_days     |
        |                         | present_days     |
        |                         | absent_days      |
        |                         | unpaid_leave_days|
        |                         | gross_salary     |
        |                         | unpaid_deduction |
        |                         | absence_deduction|
        |                         | taxable_salary   |
        |                         | tax_amount       |
        |                         | net_salary       |
        |                         | status           |
        |                         +------------------+
        |
        |                         +------------------+
        +-------------------------| audit_logs       |
                                  |------------------|
                                  | id PK            |
                                  | user_id FK       |
                                  | action           |
                                  | module           |
                                  | model_type       |
                                  | model_id         |
                                  | old_values       |
                                  | new_values       |
                                  | ip_address       |
                                  | user_agent       |
                                  | created_at       |
                                  +------------------+


+------------------+              +------------------+
| departments      | 1          N | positions        |
|------------------|--------------|------------------|
| id PK            |              | id PK            |
| name             |              | department_id FK |
| status           |              | name             |
| deleted_at       |              | status           |
+------------------+              | deleted_at       |
        |                         +------------------+
        |
        N
+------------------+
| employees        |
+------------------+


+-----------------------+
| company_settings      |
|-----------------------|
| id PK                 |
| company_name          |
| company_logo          |
| company_address       |
| company_phone         |
| company_email         |
| office_latitude       |
| office_longitude      |
| allowed_radius_meters |
| working_start_time    |
| working_end_time      |
| working_days          |
| salary_currency       |
| payroll_day_rate      |
+-----------------------+


+------------------+
| public_holidays  |
|------------------|
| id PK            |
| holiday_date     |
| name             |
| description      |
| status           |
+------------------+


FULL FINAL MVP MYSQL SCHEMA
================================================================================

1. USERS TABLE
================================================================================
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

Note:
- Do not add role_id.
- Spatie Laravel Permission handles roles and permissions.


2. SPATIE PERMISSION TABLES
================================================================================
Created by Spatie Laravel Permission package migration.

Tables:
- roles
- permissions
- model_has_roles
- model_has_permissions
- role_has_permissions

Roles:
- admin
- hr
- ceo
- employee


3. DEPARTMENTS TABLE
================================================================================
CREATE TABLE departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL
);


4. POSITIONS TABLE
================================================================================
CREATE TABLE positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,

    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,

    INDEX idx_positions_department_id (department_id),
    INDEX idx_positions_status (status)
);


5. EMPLOYEES TABLE
================================================================================
CREATE TABLE employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED UNIQUE NOT NULL,
    employee_id VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    gender ENUM('male', 'female', 'other') NULL,
    date_of_birth DATE NULL,
    phone_number VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    address TEXT NULL,
    department_id BIGINT UNSIGNED NULL,
    position_id BIGINT UNSIGNED NULL,
    join_date DATE NOT NULL,
    last_working_date DATE NULL,
    base_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    employment_status ENUM('full-time', 'resigned', 'terminated') DEFAULT 'full-time',
    emergency_contact TEXT NULL,
    profile_photo VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL,

    INDEX idx_employees_user_id (user_id),
    INDEX idx_employees_department_id (department_id),
    INDEX idx_employees_position_id (position_id),
    INDEX idx_employees_employment_status (employment_status),
    INDEX idx_employees_join_date (join_date),
    INDEX idx_employees_last_working_date (last_working_date)
);

Note:
- base_salary is NOT NULL DEFAULT 0.00 to keep payroll calculation safe.
- Avoid nullable salary because NULL can break payroll logic.


6. COMPANY SETTINGS TABLE
================================================================================
This table should usually have only one row.

CREATE TABLE company_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    company_logo VARCHAR(255) NULL,
    company_address TEXT NULL,
    company_phone VARCHAR(50) NULL,
    company_email VARCHAR(255) NULL,
    office_latitude DECIMAL(10,8) NULL,
    office_longitude DECIMAL(11,8) NULL,
    allowed_radius_meters INT DEFAULT 100,
    working_start_time TIME DEFAULT '08:00:00',
    working_end_time TIME DEFAULT '17:00:00',
    working_days JSON NOT NULL,
    salary_currency CHAR(3) DEFAULT 'USD',
    payroll_day_rate INT DEFAULT 26,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

Example working_days:
["monday", "tuesday", "wednesday", "thursday", "friday", "saturday"]

Single-row rule:
- Enforce in CompanySettingService.
- If row exists, update it.
- If no row exists, create it.
- Do not create multiple rows.


7. PUBLIC HOLIDAYS TABLE
================================================================================
CREATE TABLE public_holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_public_holidays_date (holiday_date),
    INDEX idx_public_holidays_status (status)
);


8. ATTENDANCES TABLE
================================================================================
CREATE TABLE attendances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    clock_in_time TIMESTAMP NULL,
    clock_out_time TIMESTAMP NULL,
    clock_in_latitude DECIMAL(10,8) NULL,
    clock_in_longitude DECIMAL(11,8) NULL,
    clock_out_latitude DECIMAL(10,8) NULL,
    clock_out_longitude DECIMAL(11,8) NULL,
    status ENUM('present', 'late', 'absent', 'missing_clock_out') NOT NULL,
    is_late BOOLEAN DEFAULT FALSE,

    correction_reason TEXT NULL,
    corrected_by BIGINT UNSIGNED NULL,
    corrected_at TIMESTAMP NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (corrected_by) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY unique_attendance_employee_date (employee_id, attendance_date),
    INDEX idx_attendances_employee_id (employee_id),
    INDEX idx_attendances_date (attendance_date),
    INDEX idx_attendances_employee_date (employee_id, attendance_date),
    INDEX idx_attendances_status (status),
    INDEX idx_attendances_date_status (attendance_date, status),
    INDEX idx_attendances_corrected_by (corrected_by)
);

MVP Note:
- correction_reason, corrected_by, corrected_at are enough for first version.
- If full correction history is needed later, add attendance_corrections table.
- idx_attendances_date_status helps daily attendance reports filtered by status.


9. LEAVE REQUESTS TABLE
================================================================================
CREATE TABLE leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    leave_type ENUM('annual', 'sick', 'maternity', 'unpaid') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    duration_type ENUM('full_day', 'half_day') NOT NULL DEFAULT 'full_day',
    total_days DECIMAL(5,2) NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',

    hr_approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    hr_approved_by BIGINT UNSIGNED NULL,
    hr_approved_at TIMESTAMP NULL,

    ceo_approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    ceo_approved_by BIGINT UNSIGNED NULL,
    ceo_approved_at TIMESTAMP NULL,

    rejection_reason TEXT NULL,
    cancelled_at TIMESTAMP NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (hr_approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (ceo_approved_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_leave_requests_employee_id (employee_id),
    INDEX idx_leave_requests_leave_type (leave_type),
    INDEX idx_leave_requests_status (status),
    INDEX idx_leave_requests_start_date (start_date),
    INDEX idx_leave_requests_end_date (end_date),
    INDEX idx_leave_requests_date_range (start_date, end_date),
    INDEX idx_leave_requests_hr_approval_status (hr_approval_status),
    INDEX idx_leave_requests_ceo_approval_status (ceo_approval_status)
);

MVP Note:
- No leave_balances table needed first.
- Calculate leave balance from approved leave_requests.
- Example:
  Annual remaining = 18 - SUM(approved annual leave total_days for current year)
  Sick remaining = 7 - SUM(approved sick leave total_days for current year)
  Unpaid leave has no balance limit and affects payroll.


10. PAYROLL BATCHES TABLE
================================================================================
Payroll batch represents one monthly payroll run.

CREATE TABLE payroll_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    month INT NOT NULL,
    year INT NOT NULL,
    status ENUM('draft', 'pending_approval', 'approved', 'rejected') DEFAULT 'draft',

    generated_by BIGINT UNSIGNED NULL,
    generated_at TIMESTAMP NULL,

    submitted_by BIGINT UNSIGNED NULL,
    submitted_at TIMESTAMP NULL,

    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,

    rejected_by BIGINT UNSIGNED NULL,
    rejected_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY unique_payroll_month_year (month, year),
    INDEX idx_payroll_batches_status (status),
    INDEX idx_payroll_batches_month_year (month, year)
);

Note:
- unique_payroll_month_year prevents duplicate payroll generation for same month/year.


11. PAYROLL ITEMS TABLE
================================================================================
Payroll item represents one employee's payroll inside a monthly payroll batch.

CREATE TABLE payroll_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_batch_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,

    base_salary DECIMAL(15,2) NOT NULL,
    daily_rate DECIMAL(10,2) NOT NULL,

    working_days DECIMAL(5,2) DEFAULT 0.00,
    present_days DECIMAL(5,2) DEFAULT 0.00,
    absent_days DECIMAL(5,2) DEFAULT 0.00,
    unpaid_leave_days DECIMAL(5,2) DEFAULT 0.00,

    gross_salary DECIMAL(15,2) NOT NULL,
    unpaid_deduction DECIMAL(15,2) DEFAULT 0.00,
    absence_deduction DECIMAL(15,2) DEFAULT 0.00,
    taxable_salary DECIMAL(15,2) NOT NULL,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    net_salary DECIMAL(15,2) NOT NULL,

    status ENUM('draft', 'locked') DEFAULT 'draft',

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (payroll_batch_id) REFERENCES payroll_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,

    UNIQUE KEY unique_payroll_item_employee_batch (payroll_batch_id, employee_id),
    INDEX idx_payroll_items_payroll_batch_id (payroll_batch_id),
    INDEX idx_payroll_items_employee_id (employee_id),
    INDEX idx_payroll_items_status (status)
);

MVP Note:
- daily_rate DECIMAL(10,2) is enough for this MVP.
- gross_salary appears before deductions for clearer payroll structure.
- No tax_brackets table needed first.
- Put Cambodia resident salary tax logic in PayrollService, TaxCalculationService, or config/hr.php.
- If tax rules need admin UI later, add tax_brackets table.


12. AUDIT LOGS TABLE
================================================================================
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(255) NOT NULL,
    module VARCHAR(100) NOT NULL,
    model_type VARCHAR(255) NULL,
    model_id BIGINT UNSIGNED NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP NULL,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_audit_logs_user_id (user_id),
    INDEX idx_audit_logs_action (action),
    INDEX idx_audit_logs_module (module),
    INDEX idx_audit_logs_model (model_type, model_id),
    INDEX idx_audit_logs_created_at (created_at)
);

MVP Note:
- No backups table needed first.
- Track backup create/download actions in audit_logs.
- If Admin needs backup list/history UI later, add backups table.


MVP BUSINESS RULES SUPPORTED
================================================================================

1. Authentication and Roles
--------------------------------------------------------------------------------
- users table handles login account
- Spatie tables handle roles and permissions
- no role_id in users table

2. Employee Management
--------------------------------------------------------------------------------
- employees table stores HR profile data
- supports active, resigned, terminated
- supports last_working_date
- supports base_salary
- supports profile_photo

3. Department and Position
--------------------------------------------------------------------------------
- departments table stores department list
- positions table stores position list
- positions can optionally belong to department

4. Company Settings
--------------------------------------------------------------------------------
- company_settings stores company profile
- stores GPS office location
- stores allowed_radius_meters
- stores working hours
- stores working days
- stores payroll day rate
- single-row rule is enforced in service logic

5. Public Holidays
--------------------------------------------------------------------------------
- public_holidays stores holidays
- used to prevent absent marking on holidays
- used to avoid payroll deduction on holidays

6. Attendance
--------------------------------------------------------------------------------
- attendances stores clock-in and clock-out
- stores GPS location
- unique employee_id + attendance_date prevents duplicate daily attendance
- supports late status
- supports missing clock-out
- supports manual correction with reason
- supports latest correction info for MVP
- supports date + status filtering for reports

7. Leave
--------------------------------------------------------------------------------
- leave_requests supports annual, sick, maternity, unpaid
- supports full_day and half_day
- supports HR + CEO approval
- supports rejection reason
- supports cancellation while pending
- leave balance is calculated dynamically from approved leave_requests

8. Payroll
--------------------------------------------------------------------------------
- payroll_batches represents monthly payroll run
- payroll_items represents employee payroll details
- unique month + year prevents duplicate payroll
- supports HR generate and submit
- supports CEO approve/reject
- supports salary deduction, tax amount, and net salary
- payslip PDF generated on demand from payroll data

9. Audit Logs
--------------------------------------------------------------------------------
- audit_logs tracks important actions
- visible only to Admin and CEO
- can track backup actions without backups table


RECOMMENDED ENUM VALUES
================================================================================

users.status:
- active
- inactive

departments.status:
- active
- inactive

positions.status:
- active
- inactive

employees.gender:
- male
- female
- other

employees.employment_status:
- full-time
- resigned
- terminated

public_holidays.status:
- active
- inactive

attendances.status:
- present
- late
- absent
- missing_clock_out

leave_requests.leave_type:
- annual
- sick
- maternity
- unpaid

leave_requests.duration_type:
- full_day
- half_day

leave_requests.status:
- pending
- approved
- rejected
- cancelled

leave_requests.hr_approval_status:
- pending
- approved
- rejected

leave_requests.ceo_approval_status:
- pending
- approved
- rejected

payroll_batches.status:
- draft
- pending_approval
- approved
- rejected

payroll_items.status:
- draft
- locked


RECOMMENDED DEFAULT SETTINGS
================================================================================

company_settings:
- working_start_time = 08:00:00
- working_end_time = 17:00:00
- working_days = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday"]
- salary_currency = USD
- payroll_day_rate = 26
- allowed_radius_meters = 100

leave entitlement:
- annual leave = 18 days per year
- sick leave = 7 days per year
- maternity leave = 90 days per case
- unpaid leave = no balance limit, affects payroll

payroll:
- daily_rate = base_salary / payroll_day_rate
- payroll_day_rate default = 26
- late does not deduct salary
- absent deducts salary
- unpaid leave deducts salary
- public holidays do not deduct salary


INDEX SUMMARY
================================================================================

Important indexes included:
- employees(user_id)
- employees(department_id)
- employees(position_id)
- employees(employment_status)
- employees(join_date)
- employees(last_working_date)
- positions(department_id)
- public_holidays(holiday_date)
- attendances(attendance_date)
- attendances(employee_id, attendance_date)
- attendances(status)
- attendances(attendance_date, status)
- leave_requests(start_date, end_date)
- leave_requests(status)
- leave_requests(employee_id)
- payroll_batches(month, year)
- payroll_batches(status)
- payroll_items(payroll_batch_id)
- payroll_items(employee_id)
- audit_logs(user_id)
- audit_logs(module)
- audit_logs(model_type, model_id)
- audit_logs(created_at)


FUTURE TABLES IF NEEDED
================================================================================

1. attendance_corrections
--------------------------------------------------------------------------------
Use when:
- full correction history is required
- attendance correction history report must show every change

Suggested fields:
- id
- attendance_id
- corrected_by
- old_values
- new_values
- reason
- corrected_at
- created_at
- updated_at


2. leave_balances
--------------------------------------------------------------------------------
Use when:
- leave balance calculation becomes slow
- carry-forward is needed
- manual adjustment is needed
- yearly leave snapshot is needed

Suggested fields:
- id
- employee_id
- leave_type
- year
- entitled_days
- used_days
- remaining_days
- created_at
- updated_at


3. tax_brackets
--------------------------------------------------------------------------------
Use when:
- admin needs to update tax brackets from UI
- tax rules need version/history
- payroll tax changes often

Suggested fields:
- id
- min_salary
- max_salary
- tax_rate
- deduction_amount
- status
- created_at
- updated_at


4. backups
--------------------------------------------------------------------------------
Use when:
- Admin needs backup list/history screen
- backup metadata must be stored
- backup download history needs more detail than audit logs

Suggested fields:
- id
- file_name
- file_path
- file_size
- created_by
- downloaded_by
- downloaded_at
- created_at
- updated_at


LARAVEL IMPLEMENTATION NOTES
================================================================================

Use migrations instead of running this SQL directly.

Use Spatie Laravel Permission for:
- roles
- permissions
- role assignment
- permission checks

Use services for:
- AttendanceService
- LeaveService
- PayrollService
- TaxCalculationService
- PayslipService
- ReportService
- AuditLogService
- BackupService if backup is implemented
- CompanySettingService

Use policies or permissions for:
- EmployeePolicy
- AttendancePolicy
- LeavePolicy
- PayrollPolicy
- PayslipPolicy
- ReportPolicy
- AuditLogPolicy

Use transactions for:
- attendance correction
- leave approval/rejection
- payroll generation
- payroll approval/rejection
- backup creation if implemented

Use Laravel Storage for:
- employee profile photo
- company logo
- backup files if implemented

Use DomPDF for:
- payslip PDF download

Use Laravel Excel for:
- payroll reports
- attendance reports
- leave reports


FINAL VERDICT
================================================================================

This schema is production-ready for the MVP.

It is lean enough to finish faster, but still supports:
- employee management
- departments and positions
- GPS attendance
- attendance correction
- leave request with HR + CEO approval
- monthly payroll generation
- payroll approval
- payslip PDF
- reports
- settings
- audit logs
- Spatie roles and permissions

Add optional tables only when the project really needs them.


END OF FILE
================================================================================
