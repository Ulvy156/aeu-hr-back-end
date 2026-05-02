# Roles & Permissions API

## Purpose

Describe the currently implemented authorization foundation: seeded roles, permission naming, and how frontend can discover user roles and effective permissions from auth responses.

## Base Endpoint

No standalone public role or permission API endpoints are implemented yet.

## Auth Requirement

- There is no dedicated role/permission route yet.
- Role names and effective permissions are currently exposed through the Auth API response for `POST /api/login` and `GET /api/me`.

## Permissions

### Seeded Roles

- `admin`
- `hr`
- `ceo`
- `employee`

### Permission Naming Convention

Permissions use `module.action` names. Examples from backend config:

- `departments.view_any`
- `positions.create`
- `employees.update_salary`
- `attendance.clock_in`
- `leaves.approve_hr`
- `payrolls.approve`
- `audit_logs.view`
- `company_settings.update`

## Endpoint List

- No standalone role/permission API endpoints are implemented yet.
- Read [AUTH_API.md](D:/AEU/Thesis/HR/aeu-hr-back-end/.claude/api/AUTH_API.md) for the currently available role and permission data in auth responses.

## Request Body Fields

None for this module at the moment.

## Query Parameters

None for this module at the moment.

## Response Example

Role data currently appears inside the authenticated user payload:

```json
{
  "success": true,
  "message": "Authenticated user fetched successfully.",
  "data": {
    "id": 1,
    "name": "System Admin",
    "email": "admin@example.com",
    "status": "active",
    "roles": ["admin"],
    "permissions": [
      "employees.create",
      "employees.update",
      "employees.view"
    ],
    "created_at": "2026-05-02T09:00:00.000000Z",
    "updated_at": "2026-05-02T09:00:00.000000Z"
  }
}
```

## Validation Notes

- Do not assume future role or permission endpoints exist until they are added to backend routes.

## Frontend Notes

- Use `roles` and `permissions` from auth responses for role-aware and permission-aware UI state.
- Effective permissions are resolved from Spatie Laravel Permission. Do not expect a `role_id` column on the users table.
- Do not hardcode authorization behavior from frontend assumptions alone.
- When user management or role APIs are implemented later, document them in this file only.
