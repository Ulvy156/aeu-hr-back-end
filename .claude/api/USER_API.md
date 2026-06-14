# User API

## Purpose

Manage application users, their status, and their assigned Spatie roles.

Business rule: each user must have exactly one role.

## Base Endpoint

```txt
/api/users
```

## Auth Requirement

All user management endpoints require a Sanctum bearer token.

## Authorization

- `admin` and `hr` users can list (`GET /api/users`) and search (`GET /api/users/search`) users via `users.view_any` and `users.search`.
- All other endpoints in this module (create, view single user, update, delete, role/permission management) are restricted to `admin` users only.
- User CRUD uses the existing `users.*` permissions through the backend policy.
- User dropdown search uses `users.search`.
- Role lookup uses `roles_permissions.roles_view`.
- Permission lookup uses `roles_permissions.permissions_view`.
- Role assignment uses `users.assign_roles`.
- Direct user permission management uses `users.assign_permissions`.

## Endpoint List

- `GET /api/users`
- `GET /api/users/search`
- `POST /api/users`
- `GET /api/users/{user}`
- `PUT /api/users/{user}`
- `DELETE /api/users/{user}`
- `GET /api/roles`
- `GET /api/permissions`
- `PUT /api/users/{user}/roles`
- `GET /api/users/{user}/permissions`
- `PUT /api/users/{user}/permissions`
- `POST /api/users/{user}/permissions`
- `DELETE /api/users/{user}/permissions`

---

## GET /api/users/search

Return a lightweight user list for dropdown search.

This endpoint is not paginated and returns at most `15` results.

### Query Parameters

- `q`: optional string; when blank or missing, the endpoint returns `[]`

### Search Behavior

- Case-insensitive `LIKE %q%`
- Searches `users.name`
- Searches `users.email`
- Orders by `name` ascending
- Limits results to `15`

### Authorization

- `admin` and `hr` users can access this endpoint
- Other roles receive `403 Forbidden`

### Response Example

```json
[
  {
    "user_id": 1,
    "name": "Vy Rith",
    "email": "vy@gmail.com",
    "display": "Vy Rith (vy@gmail.com)"
  }
]
```

### Frontend Notes

- Use this endpoint for searchable dropdowns only.
- It intentionally returns a minimal payload.
- Empty queries return `[]`.
- No-result queries return `[]`.

---

## GET /api/users

Return a paginated user list.

Soft-deleted users are excluded from the default list.

### Authorization

- `admin` and `hr` users can access this endpoint
- Other roles receive `403 Forbidden`

### Query Parameters

- `search`: optional string, searches by user name, user email, employee full name, employee department name, and employee position name
- `status`: optional enum, `active` or `inactive`
- `without_employee`: optional boolean-like flag; when `1`, only users without a linked employee profile are returned
- `exclude_admin`: optional boolean-like flag; when `1`, users with the `admin` role are excluded
- `per_page`: optional integer, `1` to `100`

### Response Example

```json
{
  "success": true,
  "message": "Users fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "Finance User",
      "email": "finance.user@example.com",
      "status": "active",
      "roles": ["employee"],
      "employee": {
        "id": 3,
        "employee_id": "EMP300",
        "full_name": "Finance User",
        "employment_status": "active",
        "probation_end_date": null,
        "department": {
          "id": 1,
          "name": "Finance"
        },
        "position": {
          "id": 2,
          "name": "Accountant"
        }
      },
      "created_at": "2026-05-02T10:00:00.000000Z",
      "updated_at": "2026-05-02T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### Frontend Notes

- Sensitive fields like `password`, `remember_token`, and tokens are never returned.
- `employee` can be `null` when the user is not linked to an employee record.
- `roles` is still returned as an array for response consistency, but the backend enforces exactly one role per user.
- `GET /api/users?without_employee=1` can be used to populate employee-linking selectors safely.
- `GET /api/users?without_employee=1&exclude_admin=1` is the safer query for employee-linking selectors because it returns only unlinked non-admin users.
- The safe selector query also excludes soft-deleted users and users linked to soft-deleted employee records.

---

## POST /api/users

Create a user and assign exactly one role.

### Request Body

```json
{
  "name": "CEO User",
  "email": "ceo.user@example.com",
  "password": "Secretpass-123",
  "status": "active",
  "roles": ["ceo"]
}
```

### Request Fields

- `name`: required string, max `255`
- `email`: required valid email, unique in `users`
- `password`: required string, max `255`, strong password (min `8` chars, must contain letters, mixed case, numbers, and symbols)
- `status`: required enum, `active` or `inactive`
- `roles`: required array, must contain exactly one role name
- `roles.*`: required string, must exist in Spatie `roles` table

### Response Example

```json
{
  "success": true,
  "message": "User created successfully.",
  "data": {
    "id": 7,
    "name": "CEO User",
    "email": "ceo.user@example.com",
    "status": "active",
    "roles": ["ceo"],
    "employee": null,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

### Validation Notes

- Password is hashed by the backend model cast.
- Roles are assigned through Spatie with `syncRoles`.
- The backend rejects requests that submit more than one role.

---

## GET /api/users/{user}

Return one user with their single assigned role, effective permissions, and linked employee profile if present.

### Response Example

```json
{
  "success": true,
  "message": "User fetched successfully.",
  "data": {
    "id": 5,
    "name": "HR User",
    "email": "hr.user@example.com",
    "status": "active",
    "roles": ["hr"],
    "permissions": [
      "departments.create",
      "employees.view_any",
      "positions.update"
    ],
    "employee": {
      "id": 4,
      "employee_id": "EMP301",
      "full_name": "HR User",
      "employment_status": "active",
      "probation_end_date": null,
      "department": {
        "id": 2,
        "name": "Operations"
      },
      "position": {
        "id": 5,
        "name": "Officer"
      }
    },
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

---

## PUT /api/users/{user}

Update user basic fields and optionally replace the single assigned role.

### Request Body

```json
{
  "name": "Updated User",
  "email": "updated.user@example.com",
  "status": "active",
  "roles": ["hr"]
}
```

### Validation Notes

- `email` must remain unique except for the current user.
- Password is not updated by this endpoint.
- `roles` is optional on update.
- If `roles` is provided, it must contain exactly one valid Spatie role name.
- If `roles` is omitted, the current role stays unchanged.
- Admin users cannot delete themselves through this endpoint.
- When a target user is set to `inactive`, current Sanctum tokens for that target user are revoked by the backend.

### Response Notes

- The response still returns `roles` as an array with exactly one role.

---

## DELETE /api/users/{user}

Soft delete a user instead of hard deleting the database record.

### Response Example

```json
{
  "success": true,
  "message": "User deleted successfully.",
  "data": {
    "id": 9,
    "name": "Updated User",
    "email": "updated.user@example.com",
    "status": "inactive",
    "roles": ["employee"],
    "employee": null,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

### Validation Notes

- This endpoint sets the user `status` to `inactive` and then soft deletes the user record.
- Admin users cannot delete themselves.
- If the user has a linked employee profile, the employee record is also soft deleted in the same transaction.
- Current Sanctum tokens for the target user are revoked.
- No hard delete is used in the normal delete flow.

---

## GET /api/roles

Return available Spatie roles.

### Response Example

```json
{
  "success": true,
  "message": "Roles fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "admin"
    },
    {
      "id": 2,
      "name": "hr"
    }
  ]
}
```

---

## GET /api/permissions

Return available Spatie permissions.

### Response Example

```json
{
  "success": true,
  "message": "Permissions fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "users.assign_roles",
      "module": "users"
    },
    {
      "id": 2,
      "name": "departments.view_any",
      "module": "departments"
    }
  ]
}
```

### Frontend Notes

- `module` is derived safely from the permission name prefix before the first dot.

---

## PUT /api/users/{user}/roles

Replace the assigned role of a user.

### Request Body

```json
{
  "roles": ["hr"]
}
```

### Validation Notes

- Roles must exist in the Spatie `roles` table.
- The request must contain exactly one role.
- The backend uses `$user->syncRoles($roles)`.
- No `role_id` is stored on the `users` table.

### Response Example

```json
{
  "success": true,
  "message": "User roles updated successfully.",
  "data": {
    "id": 10,
    "name": "Employee User",
    "email": "employee.user@example.com",
    "status": "active",
    "roles": ["hr"],
    "employee": null,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z"
  }
}
```

---

## GET /api/users/{user}/permissions

Return a user permission summary split by direct permissions, role permissions, and effective permissions.

### Required Permission

- `users.view`

### Response Example

```json
{
  "success": true,
  "message": "User permissions fetched successfully.",
  "data": {
    "user_id": 10,
    "direct_permissions": ["attendance.view_correction"],
    "role_permissions": ["attendance.clock_in", "attendance.view_own"],
    "all_permissions": ["attendance.clock_in", "attendance.view_correction", "attendance.view_own"]
  }
}
```

---

## PUT /api/users/{user}/permissions

Replace only the user's direct permissions.

### Required Permission

- `users.assign_permissions`

### Request Body

```json
{
  "permissions": [
    "attendance.view_any",
    "attendance.view_correction"
  ]
}
```

### Validation Notes

- `permissions` must be an array.
- Every permission name must already exist in the Spatie `permissions` table.
- This endpoint replaces direct permissions only.
- Role-based permissions are not removed or changed.
- Admin users cannot assign direct permissions to themselves through this endpoint.

---

## POST /api/users/{user}/permissions

Add one direct permission to the user.

### Required Permission

- `users.assign_permissions`

### Request Body

```json
{
  "permission": "attendance.view_correction"
}
```

### Notes

- Unknown permission names are rejected.
- Duplicate direct permissions are ignored safely.
- Role-based permissions are not changed.

---

## DELETE /api/users/{user}/permissions

Remove one direct permission from the user.

### Required Permission

- `users.assign_permissions`

### Request Body

```json
{
  "permission": "attendance.view_correction"
}
```

### Notes

- This removes direct permission assignment only.
- If the same permission still comes from the user's role, it remains in `role_permissions` and `all_permissions`.
- Admin users cannot remove their own direct permissions through this endpoint.

## Audit Notes

- User create, update, delete, role assignment, and direct permission assignment actions are written through `AuditLogService`.
- Audit records do not include passwords, remember tokens, or Sanctum token values.
