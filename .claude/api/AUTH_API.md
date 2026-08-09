# Auth API

## Purpose

Authentication for API clients using Laravel Sanctum bearer tokens (access token only, no refresh tokens).

## Base Endpoint

```txt
/api
```

## Auth Requirement

- `POST /api/login`: public (rate-limited)
- `POST /api/logout`: authenticated with Sanctum bearer token
- `GET /api/me`: authenticated with Sanctum bearer token
- `GET /api/profile`: authenticated with Sanctum bearer token
- `POST /api/profile/change-password`: authenticated with Sanctum bearer token

## Token Strategy

- **Access token**: Long-lived (30 days default). Sent in `Authorization: Bearer {token}` header. Frontend stores it in localStorage or memory.
- **No refresh token**: The access token lasts 30 days. When it expires, the user re-authenticates.
- **No cookies**: Tokens are returned in the response body and sent via the `Authorization` header. No cross-domain cookie issues.

## Permissions

- No separate permission middleware is applied to these endpoints.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie role names in `roles`.
- `POST /api/login` and `GET /api/me` return the authenticated user's effective Spatie permissions in `permissions`.
- `GET /api/profile` and `POST /api/profile/change-password` operate on the authenticated user only — there is no permission to manage another user's password here. Admin/HR resetting another user's password is a separate endpoint: see `.claude/api/USER_API.md` (`POST /api/users/{user}/reset-password`, requires `users.reset_password`).

## Endpoint List

- `POST /api/login`
- `POST /api/logout`
- `GET /api/me`
- `GET /api/profile`
- `POST /api/profile/change-password`

---

## POST /api/login

Authenticate a user and issue an access token.

### Request Body

```json
{
  "email": "admin@example.com",
  "password": "password",
  "device_name": "web-client"
}
```

### Request Fields

- `email`: required, valid email format, lowercased before validation
- `password`: required string
- `device_name`: optional string, max 255

### Response Example

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "access_token": "1|plain-text-token",
    "token_type": "Bearer",
    "expires_in": 2592000,
    "user": {
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
      "employee": null,
      "created_at": "2026-05-02T09:00:00.000000Z",
      "updated_at": "2026-05-02T09:00:00.000000Z"
    }
  }
}
```

### Response Fields

- `access_token`: Bearer token for API requests. Expires after `expires_in` seconds.
- `token_type`: Always `"Bearer"`.
- `expires_in`: Access token lifetime in seconds (default: 2592000 = 30 days).
- `user`: Authenticated user with roles, permissions, and employee data.

### Validation Notes

- Invalid credentials return:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

- Inactive accounts return:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["This account is inactive."]
  }
}
```

- Non-admin users must have a linked `Employee` profile with `employment_status` of `full-time`, `probation`, or `intern` to log in. Users without an employee profile, or whose employee profile is `resigned`/`terminated`, are rejected:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["Your account is not linked to an active employee profile. Please contact HR."]
  }
}
```

- The `admin` role is exempt from the employee-profile check above.

- Login is rate limited. When exceeded, the response is:

```json
{
  "success": false,
  "message": "Too many login attempts. Please try again later.",
  "errors": {
    "email": ["Too many login attempts. Please try again later."]
  }
}
```

---

## POST /api/logout

Revoke the current access token.

### Auth

```txt
Authorization: Bearer {access_token}
```

### Request Body

None.

### Response Example

```json
{
  "success": true,
  "message": "Logout successful.",
  "data": null
}
```

### Validation Notes

- Unauthenticated requests return the global `401` JSON error format.

---

## GET /api/me

Return the authenticated user for the active bearer token.

### Auth

```txt
Authorization: Bearer {access_token}
```

### Query Parameters

None.

### Response Example

```json
{
  "success": true,
  "message": "Authenticated user fetched successfully.",
  "data": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@example.com",
    "status": "active",
    "roles": ["admin"],
    "permissions": [
      "employees.create",
      "employees.update",
      "employees.view"
    ],
    "employee": {
      "id": 1,
      "employee_id": "EMP001",
      "full_name": "Admin User"
    },
    "created_at": "2026-05-02T09:00:00.000000Z",
    "updated_at": "2026-05-02T09:00:00.000000Z"
  }
}
```

---

## GET /api/profile

Return the authenticated user's own profile, including their role, effective permissions, and linked employee record (if any).

### Auth

```txt
Authorization: Bearer {access_token}
```

### Response Example

```json
{
  "success": true,
  "message": "Profile fetched successfully.",
  "data": {
    "id": 5,
    "name": "John Doe",
    "email": "john@example.com",
    "status": "active",
    "roles": ["employee"],
    "permissions": [
      "attendance.clock_in",
      "leaves.create",
      "payslips.view_own"
    ],
    "employee": {
      "id": 3,
      "employee_id": "EMP001",
      "full_name": "John Doe",
      "gender": "male",
      "date_of_birth": "2000-01-01",
      "phone_number": "012345678",
      "email": "john@example.com",
      "address": "Phnom Penh",
      "department": { "id": 1, "name": "IT" },
      "position": { "id": 1, "name": "Developer" },
      "join_date": "2024-01-01",
      "last_working_date": null,
      "employment_status": "full-time",
      "profile_photo_url": null
    }
  }
}
```

`employee` is `null` for users without a linked employee record (e.g. some `admin`/`hr` accounts). Salary fields are intentionally never included in this response.

---

## POST /api/profile/change-password

Allow the authenticated user to change their own password.

### Auth

```txt
Authorization: Bearer {access_token}
```

### Request Body

```json
{
  "current_password": "old-password",
  "password": "new-password",
  "password_confirmation": "new-password"
}
```

### Request Fields

- `current_password`: required string, must match the authenticated user's current password
- `password`: required string, max `255`, must be confirmed (`password_confirmation`), strong password (min `8` chars, must contain letters, mixed case, numbers, and symbols)

### Response Example

```json
{
  "success": true,
  "message": "Password changed successfully.",
  "data": null
}
```

### Behavior

- On success, the user's password is updated and **all other Sanctum tokens for this user are revoked** — only the token used to make this request remains valid.
- All other active sessions/devices will be signed out immediately.
- The action is recorded in the audit log (`module: profile`, `action: change_password`).

### Validation Notes

- Incorrect `current_password` returns:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "current_password": ["The provided password is incorrect."]
  }
}
```

- `password` too short, missing, or not matching `password_confirmation` returns standard `422` validation errors on the `password` field.

---

## Token Lifetime

| Token | Default Lifetime | Config |
|---|---|---|
| Access token | 30 days | `sanctum.expiration` (in minutes, default 43200) |

Override via `SANCTUM_TOKEN_EXPIRATION_MINUTES` env variable.

---

## What Invalidates Tokens

| Event | Access Token |
|---|---|
| Logout | Current token deleted |
| Password change (self) | Other tokens deleted |
| Password reset (admin) | All tokens deleted |
| Account deactivated | All tokens deleted |
| Account deleted | All tokens deleted (cascade) |

---

## Frontend Integration Rules

1. **Storage**: Store the access token in localStorage (or memory if you prefer re-login on page refresh).
2. **No cookies or credentials needed**: All auth is via the `Authorization: Bearer {token}` header.
3. **CORS**: Backend allows the frontend origin via `CORS_ALLOWED_ORIGINS` env variable.
4. **401 handling**: On 401 → redirect to login.
5. **Roles & permissions**: Provided by the backend for UI decisions only. Backend remains the authorization source of truth.
6. **Employee field**: Nullable. Handle users without a linked employee record.
