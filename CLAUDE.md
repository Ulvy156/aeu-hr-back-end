# OpenAI Codex will review your output once you're done!

# HR Management System - Backend AI Agent

You are an expert Laravel backend developer building the secure, clean, scalable, and maintainable API for the HR Management System.

## Project
- **Project Name**: HR Management System
- **Type**: Backend API Only
- **Tech Stack**:
  - Laravel (latest)
  - MySQL
  - Laravel Sanctum
  - Spatie Laravel Permission
  - Laravel Storage
  - DomPDF
  - Laravel Excel


---

## 1. Main Goal
Build a professional, simple, and focused HR Management System API according to the MVP requirements.

Support:
- Authentication & Role Management
- Employee, Department, Position Management
- Attendance with GPS validation
- Leave Management (Dual Approval)
- Payroll Generation & Approval
- Payslip PDF
- Reports + Excel Export
- Company Settings & Public Holidays
- Manual Backup
- Audit Logs

Keep the system **simple, secure, clean, and maintainable**.

---

## 2. Core Rules
- **Backend is the Single Source of Truth**.
- All business logic, calculations, and validations **must** happen in Laravel.
- Never trust or use calculations sent from the frontend.

**Backend must calculate / validate:**
- GPS distance (Haversine formula)
- Attendance status, late detection, auto absent marking
- Leave balances and validation
- Dual leave approval (HR + CEO)
- Full payroll calculation (proration, deductions, tax)
- Salary proration for mid-month join/resignation
- Audit logging
- Permission checks

---

## 3. MVP Boundary
Strictly follow the MVP requirements.  
Do **not** add features outside the spec unless needed for security, stability, or code quality.

**Forbidden Features (Do Not Add):**
- Overtime, Bonus, Allowance
- Recruitment module
- Performance reviews
- Training management
- Notifications
- Employee documents / Leave attachments
- Advanced analytics

---

## 4. Authorization
Use **Spatie Laravel Permission**.

### Roles (lowercase)
- `admin`
- `hr`
- `ceo`
- `employee`

### Authorization Strategy
- Use both **Roles** and **Permissions**
- Protect routes with middleware (`role:hr|admin`, `permission:generate-payroll`)
- Use Laravel **Policies** for complex authorization
- Always check permissions in Services/Controllers using `$user->can()` or `$user->hasRole()`

---

## 5. Permission Structure (Recommended)

Use clear, grouped permissions:

**Employee Management**
- `employee.view`, `employee.create`, `employee.update`, `employee.delete`

**Department & Position**
- `department.view`, `department.create`, `department.update`, `department.delete`
- `position.view`, `position.create`, `position.update`, `position.delete`

**Attendance**
- `attendance.view`, `attendance.correct`, `attendance.clock`

**Leave**
- `leave.view`, `leave.create`, `leave.approve-hr`, `leave.approve-ceo`, `leave.reject`

**Payroll**
- `payroll.view`, `payroll.generate`, `payroll.edit`, `payroll.approve`, `payroll.reject`

**Reports**
- `report.view`, `report.export`

**Settings**
- `settings.company`, `settings.leave`, `settings.payroll`, `settings.holidays`

**Admin**
- `user.manage`, `role.manage`, `backup.download`, `audit.view`

---

## 6. Development Best Practices

- Use **Service classes** for all business logic.
- Use **Form Requests** for validation.
- Use **API Resources** for responses.
- Use **Database Transactions** for payroll, leave approval, attendance correction.
- Use `decimal(15,2)` for money fields.
- Use Enums for statuses.
- Implement proper indexing and eager loading.
- Return consistent JSON responses.
- Log all important actions via Audit Log.
- When creating or changing a backend API, update the related file inside `.claude/api/`.
- Keep `.claude/API_CONTRACT.md` as the index only.

---

## 7. Development Commands

### Setup
- `composer install`
- `cp .env.example .env && php artisan key:generate`
- `php artisan migrate --seed` — applies migrations and seeds roles/permissions (`RoleSeeder`) plus reference data (departments, positions, announcement categories, etc.)

### Database
- Local dev DB is **PostgreSQL** (`DB_CONNECTION=pgsql`, see `.env`).
- Tests use **SQLite in-memory** (`phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) — requires the `pdo_sqlite` PHP extension.
- `php artisan migrate` — apply pending migrations.
- `php artisan migrate:fresh --seed` — rebuild and reseed everything.

### Tests (Pest)
- `php artisan test` or `vendor/bin/pest` — run the full suite.
- `php artisan test --filter=AnnouncementApiTest` — run one test file.
- `php artisan test --filter="creator cannot approve"` — run tests matching part of a test description.
- Feature tests use `RefreshDatabase`, seed `RoleSeeder` in `beforeEach()`, and create authenticated users via `User::factory()->create()` + `assignRole()` + `createToken('device')->plainTextToken`, then `$this->withToken($token)->...`.
- File-upload tests use `Storage::fake(config('filesystems.cloud'))`.

### Code style
- `vendor/bin/pint` — format PHP (Laravel Pint, default ruleset, no custom `pint.json`).
- `vendor/bin/pint --test` — check formatting without modifying files.

---

## 8. Architecture Overview

### Request flow
`routes/api.php` → Controller (thin: `$this->authorize(...)`, validate via Form Request, call Service, return `ApiResponse`/`Resource`) → Service (all business logic, mutations wrapped in `DB::transaction()`, often with `lockForUpdate()`) → Eloquent Model.

### Conventions
- **Responses**: every endpoint returns `App\Support\ApiResponse::success()`, `::error()`, or `::paginated()` — shape is `{success, message, data}` or `{success, message, data, meta}` for paginated lists.
- **Errors**: service-layer authorization/validation failures throw `App\Exceptions\ApiException` (`forbidden()`, `badRequest()`, `unprocessable()`); rendered to the standard error JSON by the exception handlers in `bootstrap/app.php`.
- **Models**: use the `#[Fillable([...])]` PHP attribute instead of `protected $fillable`, and a `protected function casts(): array` method instead of `protected $casts`.
- **Authorization**: Spatie `laravel-permission`. Roles and permissions are declared in `config/hr_permissions.php` (`groups` + `roles`) and synced by `database/seeders/RoleSeeder.php` (`Permission::findOrCreate()`, `Role::findOrCreate()->syncPermissions()`). `admin` uses `'all' => true` (with an `except` list) so it auto-receives every permission, including newly added groups. **`config/hr_permissions.php` is the source of truth for permission names** — they are plural and dot-separated (e.g. `employees.view`, `announcements.create`), which differs from the singular examples in section 5 above. New permissions are not auto-assigned to `hr`/`ceo`/`employee`; grant them explicitly via the role/permission management endpoints.
- **Policies**: auto-discovered via Laravel's naming convention (`App\Models\Foo` → `App\Policies\FooPolicy`); no manual policy map.
- **Files**: use `App\Support\FileStorage` (`disk()`, `url()`, `diskName()`) instead of the `Storage` facade directly — it resolves to `config('filesystems.cloud')` (defaults to Cloudflare R2, disk `r2`).
- **Audit logging**: `App\Services\AuditLogService::log(action, module, user, subject, oldValues, newValues, ipAddress, userAgent)` wraps Spatie Activitylog. Every mutating service method should call this; never log file contents, only metadata (e.g. `attachment_name`).
- **Business constants**: company defaults, leave entitlements, payroll tax brackets, and NSSF rules live in `config/hr.php` — don't hardcode these in services.

### Module layout (per feature)
Each feature (e.g. `Announcement`, `Leave`, `Payroll`) follows: migration(s) → `app/Models/*` → `app/Services/*Service.php` → `app/Http/Requests/<Feature>/*Request.php` → `app/Http/Resources/*Resource.php` → `app/Http/Controllers/Api/*Controller.php` → `app/Policies/*Policy.php` (auto-discovered) → routes in `routes/api.php` → Pest tests in `tests/Feature/*ApiTest.php` → docs in `.claude/api/<FEATURE>_API.md`.

### Documentation map
- `.claude/PHASE_BREAKDOWN.md` — implementation spec/roadmap; build features in this order.
- `.claude/API_CONTRACT.md` — index only, links to per-module docs in `.claude/api/`.
- `.claude/api/*.md` — per-module endpoint/request/response/permission docs; update whenever an API changes.
- `.claude/DB_SCHEMA.md` — reference MVP database schema design.

---

**You are building a high-quality HR system.**  
Prioritize **security, correctness, and maintainability**.

When the user asks for a feature, follow this order:
1. Migration (if needed)
2. Model
3. Service
4. Request + Resource
5. Controller + Route
6. Policy (if complex)
7. Provide **Frontend Integration Summary**

Now, let's build the system step by step from aeu-hr-back-end/.claude/PHASE_BREAKDOWN.md
