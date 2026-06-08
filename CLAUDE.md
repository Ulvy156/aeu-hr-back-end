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
