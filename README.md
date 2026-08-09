# HR Management System - Backend API

A secure, clean, and maintainable REST API for a Human Resource Management System, built with Laravel.

## Tech Stack

- **Laravel** (latest)
- **PostgreSQL** (local dev) / **SQLite in-memory** (tests)
- **Laravel Sanctum** — token-based API authentication
- **Spatie Laravel Permission** — role & permission based authorization
- **DomPDF** — payslip PDF generation
- **Laravel Excel** — report exports
- **Laravel Storage** (Cloudflare R2) — file storage

## Features

The backend is the single source of truth for all business logic, calculations, and validations (nothing is trusted from the frontend). Implemented modules:

- **Authentication** — Sanctum token login/logout
- **Users, Roles & Permissions** — 4 roles (admin, hr, ceo, employee) via Spatie
- **Departments & Positions** — company structure management
- **Employees** — profiles, org chart hierarchy, employment history, HR-proposed upgrade requests with CEO approval
- **Attendance** — GPS-validated clock in/out, QR code attendance, late/absent detection, corrections
- **Leave Management** — annual/sick/special/maternity/special-sick leave with dual approval (HR + CEO)
- **Public Holidays** — feeds into attendance and payroll calculations
- **Payroll** — proration, tax brackets, NSSF deductions, generate → submit → approve/reject workflow
- **Payslips** — PDF generation and download
- **Company Settings** — office location, working hours, currency, etc.
- **Dashboard** — role-specific summaries
- **Reports & Excel Exports** — payroll, attendance, leave
- **Audit Logs** — tracks who did what, when
- **Manual Backup** — admin-triggered data backup/download
- **Announcements** — internal notice board with approval workflow and audience targeting
- **Recruitment** — vacancy postings and candidate pipeline tracking

For a plain-language description of every feature, see [PROJECT_FEATURES.txt](PROJECT_FEATURES.txt).
For API endpoint contracts, see [.claude/API_CONTRACT.md](.claude/API_CONTRACT.md) and the per-module docs in `.claude/api/`.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_CONNECTION=pgsql` (and matching credentials) in `.env`, then:

```bash
php artisan migrate --seed
```

This applies migrations and seeds roles/permissions (`RoleSeeder`) plus reference data (departments, positions, announcement categories, etc.).

### Database

- Local dev: **PostgreSQL**
- Tests: **SQLite in-memory** (forced by `phpunit.xml`; requires the `pdo_sqlite` PHP extension)

```bash
php artisan migrate               # apply pending migrations
php artisan migrate:fresh --seed  # rebuild and reseed everything
```

### Tests (Pest)

```bash
php artisan test                              # full suite
php artisan test --filter=AnnouncementApiTest # one test file
```

### Code Style

```bash
vendor/bin/pint         # format PHP (Laravel Pint)
vendor/bin/pint --test  # check formatting without modifying files
```

## Architecture

Request flow: `routes/api.php` → Controller (thin) → Service (business logic, wrapped in DB transactions) → Eloquent Model.

- **Responses**: consistent JSON via `App\Support\ApiResponse` (`success`/`error`/`paginated`)
- **Authorization**: Spatie roles/permissions, defined in `config/hr_permissions.php`, synced by `database/seeders/RoleSeeder.php`
- **Business constants**: company defaults, leave entitlements, tax brackets, and NSSF rules live in `config/hr.php`
- **Audit logging**: `App\Services\AuditLogService` wraps Spatie Activitylog for every mutating action

See [CLAUDE.md](CLAUDE.md) for the full development guide and conventions used by this project.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
