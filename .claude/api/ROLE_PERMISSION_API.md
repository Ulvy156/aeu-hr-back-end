# Roles & Permissions API

## Purpose

Describe the authorization foundation: seeded roles, permission naming, and where frontend can fetch role and permission data safely.

## Base Endpoint

Role and permission lookup endpoints are implemented as part of the User Management API.

## Auth Requirement

- `GET /api/roles` and `GET /api/permissions` require admin access.
- Role names and effective permissions are also exposed through the Auth API response for `POST /api/login` and `GET /api/me`.

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
- `attendance.view_correction`
- `attendance.clock_in`
- `leaves.approve_hr`
- `payrolls.approve`
- `audit_logs.view`
- `company_settings.update`

## Endpoint List

- Read [USER_API.md](D:/AEU/Thesis/HR/aeu-hr-back-end/.claude/api/USER_API.md) for:
  - `GET /api/roles`
  - `GET /api/permissions`
  - `PUT /api/users/{user}/roles`
  - `GET /api/users/{user}/permissions`
  - `PUT /api/users/{user}/permissions`
  - `POST /api/users/{user}/permissions`
  - `DELETE /api/users/{user}/permissions`
- Read [AUTH_API.md](D:/AEU/Thesis/HR/aeu-hr-back-end/.claude/api/AUTH_API.md) for role and permission data in auth responses.

## Response Example

Role data still appears inside the authenticated user payload:

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

- Do not assume any role or permission endpoint outside the documented user management routes exists.

## Frontend Notes

- Use `roles` and `permissions` from auth responses for role-aware and permission-aware UI state.
- Effective permissions are resolved from Spatie Laravel Permission. Do not expect a `role_id` column on the users table.
- Direct user permissions are separate from role permissions and are managed through the user permission endpoints.
- Use [USER_API.md](D:/AEU/Thesis/HR/aeu-hr-back-end/.claude/api/USER_API.md) for admin-facing role and permission lookup endpoints.
- Do not hardcode authorization behavior from frontend assumptions alone.
