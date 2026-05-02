# HR Management System - Backend Phase Breakdown

## Purpose

This document defines the **backend-only development phases** for the HR Management System MVP.

Use this file inside the Laravel backend project:

```txt
backend/PHASE_BREAKDOWN.md
```

The backend is the **single source of truth** for all business logic.

The backend must handle:

- Authentication
- Authorization
- Database structure
- Business calculations
- GPS validation
- Attendance logic
- Leave logic
- Payroll logic
- Payslip PDF generation
- Reports and exports
- Audit logs
- API responses

The backend must **not** generate frontend code.

---

## Backend Development Flow

For every backend feature, follow this order:

```txt
1. Migration
2. Model + relationships
3. Enum if needed
4. Service class
5. Form Request
6. Controller
7. API Resource
8. Policy / Permission check
9. Route
10. Test
11. Frontend Integration Summary
```

After every backend module, update:

```txt
API_CONTRACT.md
```

The frontend should consume the API contract instead of guessing fields.

---

## AI Agent Setup Instruction

Use this instruction when working with an AI coding agent:

```txt
The Laravel backend project setup is mostly completed.

Some packages are already installed but not fully configured yet.

Do not reinstall existing packages unless required.

Before using any package, check whether it already exists in composer.json.

If the package exists but is not configured, configure it properly inside the correct phase.

Follow the backend phase breakdown strictly.

Do not generate frontend code.

Do not skip service classes, form requests, API resources, policies, permissions, tests, or API contract updates.
```

---

# Phase 0: Backend Setup

## Status

Mostly completed.

The Laravel backend project has already been created and the base environment setup is mostly ready.

Some required packages are already installed, but they are not fully configured yet. This is acceptable because package installation and package configuration are different steps.

Package configuration must be completed inside the related backend phase before the package is used.

### Current Setup Status

```txt
Project setup: Mostly completed
Package installation: Some packages already installed
Package configuration: Not fully completed yet
Backend development: Ready to continue phase by phase
```

### Package Configuration Rule

Do not configure every package randomly at the beginning.

Configure each package only when its related backend phase starts.

Examples:

```txt
Sanctum configuration       -> Phase 1.2 Authentication
Spatie Permission config    -> Phase 1.3 Roles and Permissions
PDF package configuration   -> Phase 5.5 Payslip API
Excel/export configuration  -> Phase 7 Reports and Exports API
```

Before installing any package, check `composer.json` first.

If the package is already installed, do not reinstall it. Configure it properly instead.

## Tasks

- Laravel backend project created
- `.env` configured or prepared
- MySQL database configured or prepared
- Sanctum installed or ready to configure
- Spatie Laravel Permission installed or ready to configure
- AI Agent files prepared
- Database diagram/schema prepared
- Backend folder structure reviewed
- Package configuration will be completed in the correct backend phase

## Recommended Backend Docs

```txt
D:\AEU\Thesis\HR\aeu-hr-back-end\.claude
├── AI_AGENT.md
├── DB_SCHEMA.md
├── API_CONTRACT.md
└── PHASE_BREAKDOWN.md
```

## Deliverables

- Laravel app runs successfully
- Database connection works
- Backend folder is clean
- AI Agent can read project requirements
- Backend docs are ready

---

# Phase 1: Backend Foundation

This is the most important backend phase.

Build this before all business modules.

---

## 1.1 Database Foundation

### Tasks

- Create base migrations from `DB_SCHEMA.md`
- Add foreign keys
- Add indexes
- Add soft deletes where needed
- Add seeders where needed

### Required MVP Tables

```txt
users
Spatie permission tables
departments
positions
employees
company_settings
public_holidays
attendances
leave_requests
payroll_batches
payroll_items
audit_logs
```

### Optional Later Tables

```txt
attendance_corrections
leave_balances
tax_brackets
backups
```

## Deliverables

- All MVP migrations exist
- Tables migrate successfully
- Foreign keys work
- Indexes are added
- Optional tables are not created unless needed

---

## 1.2 Authentication

### Tasks

- Install/configure Laravel Sanctum
- Create login API
- Create logout API
- Create `/api/me` API
- Hash passwords properly
- Revoke token on logout
- Add login rate limiting

### Endpoints

```txt
POST /api/login
POST /api/logout
GET  /api/me
```

## Deliverables

- User can login
- User receives token
- User can logout
- `/api/me` returns authenticated user
- Invalid login is rejected
- Login is rate limited

---

## 1.3 Roles and Permissions

### Tasks

- Install/configure Spatie Laravel Permission
- Create roles:
  - `admin`
  - `hr`
  - `ceo`
  - `employee`
- Create grouped permissions
- Create role/permission seeder
- Assign permissions to roles
- Add middleware checks
- Add policy checks where needed

### Important Rule

Do **not** add `role_id` to users table.

Spatie handles roles through:

```txt
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions
```

## Deliverables

- Roles are seeded
- Permissions are seeded
- Users can be assigned roles
- Protected routes reject unauthorized users
- `$user->can()` works
- `$user->hasRole()` works

---

## 1.4 Global API Response Format

### Tasks

Create consistent JSON responses.

### Success Response

```json
{
  "success": true,
  "message": "Action completed successfully",
  "data": {}
}
```

### Error Response

```json
{
  "success": false,
  "message": "Something went wrong",
  "errors": {}
}
```

### Validation Error

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

### Paginated Response

```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 0
  }
}
```

## Deliverables

- API responses are consistent
- Validation errors are clean
- Frontend can handle all response formats easily

---

## 1.5 Global Exception Handling

### Tasks

Handle:

- 400 bad request
- 401 unauthenticated
- 403 forbidden
- 404 not found
- 422 validation error
- 429 too many requests
- 500 server error

### Security Rule

Never expose stack traces or sensitive server errors in production.

## Deliverables

- Errors return JSON
- Sensitive errors are hidden
- Frontend receives readable messages

---

## 1.6 Audit Log System

### Package

Use `spatie/laravel-activitylog` as the audit log package.

Do **not** build a fully custom audit log system from scratch.

Remove custom audit log migration and custom `AuditLog` model tasks because Spatie Activitylog already provides the table/model structure.

Before installing the package, check `composer.json` first. Install it only if missing.


### Tasks

- Use `spatie/laravel-activitylog`
- Publish Activitylog config and migration
- Run the migration
- Configure Activitylog
- Create `AuditLogService` as a small wrapper service
- Log important MVP actions manually through `AuditLogService`
- Store IP address and user agent inside Activitylog `properties`
- Create `GET /api/audit-logs` endpoint
- Add filters for:
  - user
  - module
  - action
  - date_from
  - date_to
- Protect audit logs so only Admin and CEO can view them
- HR and Employee must not view audit logs


### Track Actions for MVP

- Login/logout if possible
- Attendance correction
- Payroll generation
- Payroll edit
- Payroll approval/rejection
- Leave approval/rejection/cancellation
- Employee created/updated/deleted
- Employee salary change
- Company settings update
- User created/updated/disabled
- User role change


### Security Rules

- Do not log passwords
- Do not log tokens
- Do not log full request payloads blindly
- Do not log sensitive server errors
- Audit logs are read-only
- Audit logs cannot be edited or deleted from normal UI
- For salary/payroll, log only necessary old/new values


### Deliverables

- Spatie Activitylog is installed or confirmed existing
- Activitylog config and migration are completed
- `AuditLogService` exists
- Important MVP actions are logged
- Logs include user, action, module, old/new values when needed, IP, and user agent
- Audit log list API exists
- Audit log filters work
- Only Admin and CEO can view audit logs
- HR and Employee cannot view audit logs

---

## 1.7 Base Company Settings

### Tasks

- Create company setting model/service
- Seed default company settings
- Enforce single-row setting in service logic

### Default Settings

```txt
working_start_time = 08:00
working_end_time = 17:00
working_days = Monday to Saturday
salary_currency = USD
payroll_day_rate = 26
allowed_radius_meters = 100
```

## Deliverables

- Company settings exist
- Attendance can use GPS settings later
- Payroll can use payroll day rate later
- Service prevents multiple company setting rows

---

# Phase 2: Employee and Organization API

This phase builds the company structure.

---

## 2.1 Department API

### Tasks

- Department migration
- Department model
- Department enum/status
- Department service
- Store/update request validation
- Department controller
- Department resource
- Department policy/permissions
- Routes

### Endpoints

```txt
GET    /api/departments
POST   /api/departments
GET    /api/departments/{department}
PUT    /api/departments/{department}
DELETE /api/departments/{department}
```

## Deliverables

- HR/Admin can manage departments
- Department list supports pagination
- Department status works
- Department deletion is safe

---

## 2.2 Position API

### Tasks

- Position migration
- Position model
- Position service
- Store/update request validation
- Position controller
- Position resource
- Position policy/permissions
- Routes

### Endpoints

```txt
GET    /api/positions
POST   /api/positions
GET    /api/positions/{position}
PUT    /api/positions/{position}
DELETE /api/positions/{position}
```

## Deliverables

- HR/Admin can manage positions
- Position can optionally belong to department
- Position list supports filtering by department
- Position status works

---

## 2.3 Employee API

### Tasks

- Employee migration
- Employee model
- Employee service
- Employee form requests
- Employee controller
- Employee resource
- Employee policy/permissions
- Profile photo upload
- Link employee to user account

### Rules

- Employee ID must be unique
- User email must be unique
- Active employee should not have last working date
- Resigned/terminated employee must have last working date
- Base salary uses `decimal(15,2)`
- Profile photo uses Laravel Storage
- Validate image type and size

### Endpoints

```txt
GET    /api/employees
POST   /api/employees
GET    /api/employees/{employee}
PUT    /api/employees/{employee}
DELETE /api/employees/{employee}
```

## Deliverables

- HR/Admin can create employees
- HR/Admin can update employees
- HR/Admin can upload profile photo
- Employee status validation works
- Salary changes are audited
- Employee list supports pagination/filtering

---

# Phase 3: Attendance API

This phase handles GPS attendance, late detection, correction, and absent marking.

---

## 3.1 Clock In API

### Tasks

- Create Attendance model/service/controller/resource
- Create GPS validation logic using Haversine formula
- Validate latitude/longitude
- Read office GPS and radius from company settings
- Prevent duplicate clock-in
- Detect late status
- Store clock-in GPS

### Endpoint

```txt
POST /api/attendance/clock-in
```

### Request Example

```json
{
  "latitude": 11.5564,
  "longitude": 104.9282
}
```

### Error Example

```json
{
  "success": false,
  "message": "You are outside the allowed clock-in location.",
  "errors": {}
}
```

## Deliverables

- Employee can clock in
- GPS is validated on backend
- Missing GPS is rejected
- Outside radius is rejected
- Duplicate clock-in is prevented
- Late status works

---

## 3.2 Clock Out API

### Tasks

- Validate GPS
- Prevent clock-out without clock-in
- Prevent duplicate clock-out
- Store clock-out time and GPS
- Update status if needed

### Endpoint

```txt
POST /api/attendance/clock-out
```

## Deliverables

- Employee can clock out
- Invalid GPS is rejected
- Duplicate clock-out is prevented
- Missing clock-in is handled

---

## 3.3 Attendance List API

### Tasks

- Attendance listing
- Filter by employee
- Filter by date
- Filter by status
- Pagination
- Eager load employee data

### Endpoint

```txt
GET /api/attendance
```

## Deliverables

- HR/Admin can view attendance
- Employee can view own attendance
- Filters work
- Pagination works

---

## 3.4 Attendance Correction API

### Tasks

- HR/Admin correction
- Correction reason required
- Store correction fields:
  - `correction_reason`
  - `corrected_by`
  - `corrected_at`
- Audit old and new values
- Use database transaction

### Endpoint

```txt
PUT /api/attendance/{attendance}/correction
```

## Deliverables

- HR/Admin can correct attendance
- Employee cannot correct attendance
- Reason is required
- Audit log is created

---

## 3.5 Auto Absent Marking

### Tasks

Create service/command/manual endpoint to mark absent when:

- It is a working day
- It is not a public holiday
- Employee has no approved leave
- Employee has no attendance record

### Endpoint

```txt
POST /api/attendance/mark-absent
```

## Deliverables

- Absent records are created safely
- No duplicate absent records
- Join date is respected
- Last working date is respected
- Public holidays are excluded
- Approved leave is excluded

---

# Phase 4: Leave API

This phase handles leave requests, dynamic leave balances, cancellation, and dual approval.

---

## 4.1 Leave Request API

### Tasks

- LeaveRequest model/service/controller/resource
- Leave request validation
- Calculate total days on backend
- Support full day and half day
- Exclude public holidays
- Exclude non-working days
- Validate leave balance for paid leave

### Endpoint

```txt
POST /api/leaves
```

## Deliverables

- Employee can request leave
- Reason is required
- Total days calculated on backend
- Public holidays are excluded
- Non-working days are excluded
- Paid leave cannot exceed balance

---

## 4.2 Leave List and Detail API

### Tasks

- Leave list
- Leave detail
- Filter by status
- Filter by employee
- Filter by date range
- Role-based data access

### Endpoints

```txt
GET /api/leaves
GET /api/leaves/{leave}
```

## Deliverables

- Employee can view own leave
- HR/CEO can view approval lists
- Filters work
- Pagination works

---

## 4.3 Leave Balance API

### Rule

Do not create `leave_balances` table for MVP.

Calculate dynamically from approved leave requests.

### Endpoint

```txt
GET /api/leave-balances
```

## Deliverables

- Annual balance works
- Sick balance works
- Maternity rule works
- Unpaid leave has no balance limit

---

## 4.4 Leave Approval API

### Tasks

- HR approval
- CEO approval
- Approval order does not matter
- Leave becomes approved only when both approve
- If either rejects, leave becomes rejected
- Rejection reason required
- Use transaction
- Audit action

### Endpoints

```txt
POST /api/leaves/{leave}/approve
POST /api/leaves/{leave}/reject
```

## Deliverables

- HR can approve/reject
- CEO can approve/reject
- Employees cannot approve/reject
- Final status updates correctly
- Rejection reason is required
- Audit logs are created

---

## 4.5 Leave Cancellation API

### Tasks

- Employee can cancel own pending leave
- Cannot cancel approved/rejected leave
- Audit cancellation if needed

### Endpoint

```txt
POST /api/leaves/{leave}/cancel
```

## Deliverables

- Pending leave can be cancelled
- Non-pending leave cannot be cancelled
- Employee can only cancel own leave

---

# Phase 5: Payroll and Payslip API

This is the most sensitive backend phase.

Build carefully.

---

## 5.1 Payroll Generation API

### Tasks

- PayrollBatch model/service/controller/resource
- PayrollItem model/resource
- Generate payroll for all eligible employees
- Prevent duplicate payroll for same month/year
- Use transaction
- Audit payroll generation

### Endpoint

```txt
POST /api/payrolls/generate
```

## Deliverables

- HR can generate payroll
- Duplicate payroll is rejected
- Payroll batch is created
- Payroll items are created
- Payroll generation is audited

---

## 5.2 Payroll Calculation Engine

### Must Calculate

- Base salary
- Daily rate
- Working days
- Present days
- Absent days
- Unpaid leave days
- Gross salary
- Unpaid leave deduction
- Absence deduction
- Taxable salary
- Tax amount
- Net salary

### Formula

```txt
Daily salary = base_salary / 26
Unpaid leave deduction = daily salary * unpaid leave days
Absence deduction = daily salary * absent days
Taxable salary = gross salary - unpaid leave deduction - absence deduction
Net salary = taxable salary - tax
```

### Proration

```txt
Mid-month join:
Salary = monthly salary / 26 * working days from join date

Mid-month resignation/termination:
Salary = monthly salary / 26 * working days until last working date
```

## Deliverables

- Payroll calculation is backend-only
- Proration works
- Public holidays are respected
- Absent deduction works
- Unpaid leave deduction works
- Tax calculation works

---

## 5.3 Payroll Review/Edit API

### Tasks

- HR can view payroll
- HR can edit payroll before CEO approval
- Approved payroll cannot be edited
- Audit edits

### Endpoints

```txt
GET /api/payrolls
GET /api/payrolls/{payroll}
PUT /api/payrolls/{payroll}
```

## Deliverables

- Payroll list works
- Payroll detail works
- HR can edit before approval
- Approved payroll is locked

---

## 5.4 Payroll Submit/Approval API

### Tasks

- HR submits payroll to CEO
- CEO approves payroll
- CEO rejects payroll with reason
- Approved payroll becomes locked
- Payslip becomes visible after approval
- Use transactions
- Audit actions

### Endpoints

```txt
POST /api/payrolls/{payroll}/submit
POST /api/payrolls/{payroll}/approve
POST /api/payrolls/{payroll}/reject
```

## Deliverables

- HR can submit payroll
- CEO can approve payroll
- CEO can reject payroll
- Rejection reason is required
- Approved payroll is locked
- Audit logs are created

---

## 5.5 Payslip API

### Tasks

- Employee can view own approved payslips
- Generate PDF on demand using DomPDF
- Do not permanently store PDF unless required
- Protect access by role/permission

### Endpoints

```txt
GET /api/payslips
GET /api/payslips/{payslip}
GET /api/payslips/{payslip}/download
```

## Deliverables

- Employee can view own payslip
- Employee can download own payslip PDF
- HR/Admin/CEO can access based on permission
- Unapproved payslips are hidden from employees

---

# Phase 6: Dashboard API

Build dashboard APIs after core modules exist.

---

## 6.1 Employee Dashboard API

### Data

- Today attendance
- Leave balance
- Latest approved payslip

### Endpoint

```txt
GET /api/dashboard/employee
```

---

## 6.2 HR Dashboard API

### Data

- Today attendance summary
- Pending leave requests
- Payroll status

### Endpoint

```txt
GET /api/dashboard/hr
```

---

## 6.3 CEO Dashboard API

### Data

- Pending leave approvals
- Payroll approval summary

### Endpoint

```txt
GET /api/dashboard/ceo
```

---

## 6.4 Admin Dashboard API

### Data

- User count
- System settings summary

### Endpoint

```txt
GET /api/dashboard/admin
```

## Deliverables

- Dashboard APIs are role-protected
- Dashboard data is backend-calculated
- No unauthorized data leaks

---

# Phase 7: Reports and Exports API

Reports should be built after attendance, leave, and payroll are ready.

---

## 7.1 Payroll Reports

### Reports

- Monthly payroll summary
- Employee payroll list
- Payroll status report
- Export payroll to Excel

### Endpoints

```txt
GET /api/reports/payroll
GET /api/reports/payroll/export
```

---

## 7.2 Attendance Reports

### Reports

- Daily attendance list
- Monthly attendance summary
- Late employee list
- Absent employee list
- Attendance correction list
- Export attendance to Excel

### Endpoints

```txt
GET /api/reports/attendance
GET /api/reports/attendance/export
```

---

## 7.3 Leave Reports

### Reports

- Leave request list
- Pending approval list
- Approved leave list
- Rejected leave list
- Leave balance report
- Export leave report to Excel

### Endpoints

```txt
GET /api/reports/leave
GET /api/reports/leave/export
```

## Deliverables

- Reports support filters
- Reports use pagination where needed
- Excel exports work
- Reports are permission-protected
- No frontend-generated Excel needed

---

# Phase 8: Admin and Settings API

---

## 8.1 Company Settings API

### Tasks

- View company settings
- Update company settings
- Upload company logo
- Update office GPS
- Update working hours
- Update working days
- Update payroll day rate
- Enforce single-row settings in service

### Endpoints

```txt
GET /api/settings/company
PUT /api/settings/company
```

## Deliverables

- Admin can update settings
- Company logo upload works
- GPS settings update works
- Settings changes are audited

---

## 8.2 Public Holiday API

### Tasks

- Add public holiday
- Edit public holiday
- Disable/delete public holiday
- List public holidays

### Endpoints

```txt
GET    /api/public-holidays
POST   /api/public-holidays
PUT    /api/public-holidays/{holiday}
DELETE /api/public-holidays/{holiday}
```

## Deliverables

- Admin/HR can manage holidays
- Holidays affect attendance/payroll logic
- Changes are audited where needed

---

## 8.3 User Management API

### Tasks

- Admin manages users
- Create user
- Update user
- Disable user
- Assign roles
- Manual password reset

### Endpoints

```txt
GET    /api/users
POST   /api/users
GET    /api/users/{user}
PUT    /api/users/{user}
DELETE /api/users/{user}

GET /api/roles
GET /api/permissions
PUT /api/users/{user}/roles
```

## Deliverables

- Admin can manage users
- Admin can assign roles
- Password reset works
- User management is audited

---

## 8.4 Audit Logs API

### Tasks

- Admin/CEO can view audit logs
- Filter by user
- Filter by module
- Filter by action
- Filter by date

### Endpoint

```txt
GET /api/audit-logs
```

## Deliverables

- Admin/CEO can view audit logs
- HR/Employee cannot view audit logs
- Filters work

---

## 8.5 Manual Backup API

### MVP Note

No backups table required for MVP.

Track backup actions in audit logs.

### Tasks

- Admin triggers backup if environment supports it
- Admin downloads backup if implemented
- Log backup action in audit logs

### Endpoints

```txt
POST /api/backups
GET  /api/backups/{backup}/download
```

## Deliverables

- Backup action is Admin-only
- Backup action is audited
- Backup file is not publicly exposed

---

# Phase 9: Backend Testing and Polish

Final backend phase before demo/release.

---

## 9.1 Feature Tests

Test:

- Login
- Logout
- `/api/me`
- Role authorization
- Permission authorization
- Department CRUD
- Position CRUD
- Employee CRUD
- GPS validation
- Clock in
- Clock out
- Late detection
- Missing clock-out
- Attendance correction
- Auto absent marking
- Leave request
- Leave cancellation
- HR approval
- CEO approval
- Leave rejection
- Leave balance calculation
- Payroll generation
- Duplicate payroll prevention
- Payroll calculation
- Payroll approval
- Payroll rejection
- Payslip access permission
- Report permission
- Audit log permission

---

## 9.2 Performance Review

Check:

- N+1 queries
- Pagination
- Index usage
- Report filter performance
- Payroll generation transaction safety
- Duplicate query issues

---

## 9.3 Security Review

Check:

- Sensitive endpoints are protected
- Payroll data is protected
- Audit logs are protected
- Backup is protected
- File uploads are validated
- Login is rate limited
- GPS validation is backend-only
- No sensitive data in logs

---

## 9.4 Documentation

Finalize:

- API contract
- Database schema
- Setup guide
- Seeder guide
- Role/permission guide
- Testing checklist

## Deliverables

- Backend core flows tested
- Permission leaks fixed
- Error handling completed
- API documented
- Ready for frontend integration/demo

---

# Final Backend Phase Order

```txt
Phase 0: Backend Setup
Phase 1: Backend Foundation
Phase 2: Employee and Organization API
Phase 3: Attendance API
Phase 4: Leave API
Phase 5: Payroll and Payslip API
Phase 6: Dashboard API
Phase 7: Reports and Exports API
Phase 8: Admin and Settings API
Phase 9: Backend Testing and Polish
```

---

# Backend Rules Reminder

## Backend Must Calculate

- GPS distance
- Attendance status
- Late status
- Absent days
- Leave balance
- Leave approval status
- Payroll
- Tax
- Deductions
- Net salary
- Salary proration

## Backend Must Protect

- Payroll data
- Employee salary
- Audit logs
- Backup actions
- Settings
- User management

## Backend Must Not Generate

- Vue pages
- Vue components
- Pinia stores
- Frontend route guards
- Frontend UI code

---

## Final Setup Statement

```txt
The backend project setup is mostly completed.

Some required packages are already installed, but not fully configured yet. This is acceptable because package configuration should be completed during the related backend phase.

The project is ready to continue with Phase 1: Backend Foundation.
```

---

# End of Document
